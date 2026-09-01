<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    /** @var array<int, string> */
    public array $frequencySentences = [];

    /** @var array<int, string> */
    public array $corrections = [];

    /** @var array<int, array{severity: string, hint: string, checkedText: string}> keyed by frequencySentences index */
    public array $feedback = [];

    /** @var array<int, string> keyed by frequencySentences index — per-input check failure message */
    public array $checkErrors = [];

    /** @var array<int, array{severity: string, hint: string}> keyed by corrections index — a local, non-AI verdict */
    public array $correctionFeedback = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('grammar_in_context')?->content_ref ?? '{}', true);
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $savedSentences = collect($data['frequency_sentences'] ?? [])->keyBy('starter');

        foreach ($starters as $index => $starter) {
            $this->frequencySentences[$index] = $savedSentences[$starter]['completion'] ?? '';
        }

        $this->corrections = collect($data['corrections'] ?? [])->pluck('my_correction')->all();
    }

    public function checkOne(int $index): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $starter = $starters[$index] ?? null;
        $sentence = trim($this->frequencySentences[$index] ?? '');

        if (! $starter || $sentence === '') {
            return;
        }

        $this->runCheck($index, $starter, $sentence);
    }

    /**
     * Asks the shared SentenceChecker to judge one frequency sentence,
     * storing the verdict tagged with the exact text it applies to, so a
     * later edit doesn't leave a stale verdict attached to different text.
     * See EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(int $index, string $starter, string $sentence): void
    {
        unset($this->checkErrors[$index]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner finished this sentence starter into a true, natural '
                    .'personal sentence, correctly using the present simple tense.',
                majorCriteria: 'the verb is not in the present simple tense, the sentence does not actually '
                    .'continue the given starter, or it is not a genuine personal statement',
                context: "a personal sentence that starts with \"{$starter}\" and continues in the present simple tense",
                text: $sentence,
            );

            $this->feedback[$index] = $data + ['checkedText' => $sentence];
        } catch (ConnectionException) {
            $this->checkErrors[$index] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$index] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * Quick Check has exactly one correct answer per item (a known grammar
     * fix), so this is a plain local comparison — no AI call needed. Shaped
     * like a SentenceChecker verdict anyway so the template can reuse
     * <x-severity-feedback> for both.
     */
    public function checkCorrection(int $index): void
    {
        $item = ($this->run->mission->stepContent('grammar_in_context')['quick_check'] ?? [])[$index] ?? null;
        $mine = trim($this->corrections[$index] ?? '');

        if (! $item || $mine === '') {
            return;
        }

        $this->correctionFeedback[$index] = $this->normalize($mine) === $this->normalize($item['correct'])
            ? ['severity' => 'none', 'hint' => '']
            : ['severity' => 'minor', 'hint' => 'Not quite right yet — check the verb form for this subject.'];
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower(rtrim(trim($text), '.!?'))));
    }

    public function save(): void
    {
        $starters = $this->run->mission->stepContent('grammar_in_context')['frequency_starters'] ?? [];
        $quickCheck = $this->run->mission->stepContent('grammar_in_context')['quick_check'] ?? [];

        $filledSentences = collect($this->frequencySentences)
            ->map(fn ($s, $i) => ['index' => $i, 'starter' => $starters[$i] ?? null, 'text' => trim((string) $s)])
            ->filter(fn ($s) => $s['text'] !== '');

        if ($filledSentences->count() < 3) {
            $this->addError('frequencySentences', 'Complete at least 3 sentences before continuing.');

            return;
        }

        // Every filled sentence needs a fresh verdict before Continue is
        // allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($filledSentences as $item) {
            $alreadyChecked = ($this->feedback[$item['index']]['checkedText'] ?? null) === $item['text'];

            if (! $alreadyChecked) {
                $this->runCheck($item['index'], $item['starter'], $item['text']);
            }
        }

        $hasMajorIssue = $filledSentences->contains(
            fn ($item) => ($this->feedback[$item['index']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('frequencySentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        $quickCheckResults = collect($quickCheck)->map(function ($item, $i) {
            $mine = trim($this->corrections[$i] ?? '');
            $isCorrect = $mine !== '' && $this->normalize($mine) === $this->normalize($item['correct']);

            $this->correctionFeedback[$i] = $isCorrect
                ? ['severity' => 'none', 'hint' => '']
                : ['severity' => 'minor', 'hint' => 'Not quite right yet — check the verb form for this subject.'];

            return [
                'wrong' => $item['wrong'],
                'my_correction' => $mine,
                'correct' => $item['correct'],
                'is_correct' => $isCorrect,
            ];
        });

        if ($quickCheckResults->contains(fn ($r) => ! $r['is_correct'])) {
            $this->addError('corrections', 'Fix the sentences that are still wrong before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'grammar_in_context',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'frequency_sentences' => $filledSentences
                    ->map(fn ($s) => ['starter' => $s['starter'], 'completion' => $s['text']])
                    ->values(),
                'corrections' => $quickCheckResults->values(),
            ]),
        ]);

        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $grammar = $run->mission->stepContent('grammar_in_context');
    $lesson = $grammar['lesson'] ?? [];
    $lessonSections = ['conjugation', 'questions', 'frequency'];
    $initialFilled = collect($frequencySentences)->map(fn ($s) => trim((string) $s) !== '')->values();
@endphp

<div
    class="space-y-6"
    x-data="{
        phase: '{{ $readOnly ? 'practice' : 'lesson' }}',
        lessonStep: 0,
        lessonSections: {{ count($lessonSections) }},
        filled: {{ $initialFilled->toJson() }},
        dismissed: {},
        get filledCount() { return this.filled.filter(Boolean).length },
        get progressMessage() {
            const n = this.filledCount;
            if (n === 0) return 'Fill in your first sentence below.';
            if (n === 1) return 'Nice start — keep going!';
            if (n === 2) return 'One more and you\'re ready to continue!';
            return 'Ready to continue — write more if you like!';
        },
    }"
>
    <x-hook :text="$grammar['hook'] ?? null" />

    <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ $grammar['focus'] ?? 'Grammar' }}</p>

    @unless ($readOnly)
        <div x-show="phase === 'lesson'" x-cloak class="space-y-4">
            <div>
                <x-progress-bar>
                    <div
                        class="h-full rounded-full bg-neutral-900 transition-all duration-300 dark:bg-white"
                        :style="`width: ${(lessonStep + 1) / lessonSections * 100}%`"
                    ></div>
                    <x-slot:label>
                        <p class="text-xs font-semibold text-neutral-500">
                            Lesson <span x-text="lessonStep + 1"></span> of <span x-text="lessonSections"></span>
                        </p>
                    </x-slot:label>
                </x-progress-bar>
            </div>

            {{-- A: how the verb changes --}}
            <div x-show="lessonStep === 0" x-cloak class="rounded-lg border-2 border-neutral-900 bg-neutral-50 p-4 dark:border-white dark:bg-neutral-900">
                <p class="text-sm font-bold">A · The verb changes with he / she / it</p>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    With <strong>I / we / you / they</strong> the verb stays simple. With <strong>he / she / it</strong> it takes an <strong>-s</strong> (or an irregular form, like <em>have → has</em>).
                </p>
                <div class="mt-3 space-y-2">
                    @foreach ($lesson['conjugation_examples'] ?? [] as $example)
                        <div class="grid grid-cols-2 gap-2 rounded border border-neutral-200 p-2 text-sm dark:border-neutral-800">
                            <p>{{ $example['base'] }}</p>
                            <p class="font-semibold">{{ $example['third_person'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- B: questions and negatives --}}
            <div x-show="lessonStep === 1" x-cloak class="rounded-lg border-2 border-neutral-900 bg-neutral-50 p-4 dark:border-white dark:bg-neutral-900">
                <p class="text-sm font-bold">B · Questions and negatives use do / does</p>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">
                    Use <strong>do</strong>/<strong>don't</strong> with I/we/you/they, and <strong>does</strong>/<strong>doesn't</strong> with he/she/it — the main verb goes back to its simple form.
                </p>
                <div class="mt-3 space-y-2 text-sm">
                    <p class="rounded border border-neutral-200 p-2 dark:border-neutral-800">{{ $lesson['question_example'] ?? '' }}</p>
                    <p class="rounded border border-neutral-200 p-2 dark:border-neutral-800">{{ $lesson['question_example_does'] ?? '' }}</p>
                    <p class="rounded border border-neutral-200 p-2 dark:border-neutral-800">{{ $lesson['negative_example'] ?? '' }}</p>
                    <p class="rounded border border-neutral-200 p-2 dark:border-neutral-800">{{ $lesson['negative_example_does'] ?? '' }}</p>
                </div>
            </div>

            {{-- C: word order for frequency adverbs --}}
            <div x-show="lessonStep === 2" x-cloak class="rounded-lg border-2 border-neutral-900 bg-neutral-50 p-4 dark:border-white dark:bg-neutral-900">
                <p class="text-sm font-bold">C · Where the frequency word goes</p>
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                    @foreach ($lesson['frequency_scale'] ?? [] as $word)
                        <span class="rounded-full border border-neutral-300 px-2 py-0.5 dark:border-neutral-700">{{ $word }}</span>
                        @if (! $loop->last) <span class="text-neutral-400">→</span> @endif
                    @endforeach
                </div>
                <div class="mt-3 space-y-2">
                    @foreach ($lesson['word_order_examples'] ?? [] as $rule)
                        <div class="rounded border border-neutral-200 p-2 text-sm dark:border-neutral-800">
                            <p class="text-xs text-neutral-500">{{ $rule['rule'] }}</p>
                            <p class="font-semibold">{{ $rule['example'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center justify-between">
                <button
                    type="button"
                    x-show="lessonStep > 0"
                    x-on:click="lessonStep--"
                    class="cursor-pointer text-sm text-neutral-500 underline"
                >&#8249; Back</button>
                <span x-show="lessonStep === 0"></span>

                <button
                    type="button"
                    x-show="lessonStep < lessonSections - 1"
                    x-on:click="lessonStep++"
                    class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >Next &#8250;</button>

                <button
                    type="button"
                    x-show="lessonStep === lessonSections - 1"
                    x-on:click="phase = 'practice'"
                    class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
                >Start practice &#8250;</button>
            </div>
        </div>
    @endunless

    <div x-show="phase === 'practice'" @unless ($readOnly) x-cloak @endunless class="space-y-6">
        @unless ($readOnly)
            <button
                type="button"
                x-on:click="phase = 'lesson'; lessonStep = 0"
                class="cursor-pointer text-xs font-semibold text-neutral-500 underline decoration-dotted underline-offset-2"
            >&#9656; Review the lesson again</button>
        @endunless

        <div>
            <p class="text-sm font-semibold">Make it personal</p>
            <p class="text-xs text-neutral-500">Finish at least 3 sentences about your own life. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            @unless ($readOnly)
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            :class="filledCount >= 3 ? 'bg-green-600' : 'bg-neutral-900 dark:bg-white'"
                            :style="`width: ${Math.min(filledCount, 3) / 3 * 100}%`"
                        ></div>
                        <x-slot:label>
                            <p
                                class="text-xs font-semibold transition-colors"
                                :class="filledCount >= 3 ? 'text-green-600' : 'text-neutral-600 dark:text-neutral-400'"
                                x-text="progressMessage"
                            ></p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            @endunless

            <div wire:loading.class="pointer-events-none" wire:target="checkOne,save" class="mt-2 space-y-3">
                @foreach ($grammar['frequency_starters'] ?? [] as $index => $starter)
                    @php $itemFeedback = $feedback[$index] ?? null; @endphp
                    <div class="rounded border border-neutral-300 p-3 dark:border-neutral-700">
                        <div class="flex items-center gap-2">
                            <span class="shrink-0 text-sm text-neutral-500">{{ $starter }}</span>
                            <input
                                type="text"
                                wire:model="frequencySentences.{{ $index }}"
                                x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed['freq{{ $index }}'] = true"
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkOne,save"
                                class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                            >
                            <span x-show="filled[{{ $index }}]" class="shrink-0 text-sm text-green-600">✓</span>
                            @unless ($readOnly)
                                <x-check-button method="checkOne" :index="$index" key-prefix="freq" wire-target="checkOne,save" />
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), save" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['freq{{ $index }}']" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$index] ?? null" />
                        </div>
                    </div>
                @endforeach
            </div>
            @error('frequencySentences')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <p class="text-sm font-semibold">Quick check</p>
            <p class="text-xs text-neutral-500">Correct these sentences.</p>
            <div wire:loading.class="pointer-events-none" wire:target="checkCorrection,save" class="mt-2 space-y-3">
                @foreach ($grammar['quick_check'] ?? [] as $index => $item)
                    @php $correctionItemFeedback = $correctionFeedback[$index] ?? null; @endphp
                    <div>
                        <p class="text-sm text-neutral-500 line-through decoration-red-500">{{ $item['wrong'] }}</p>
                        <div class="mt-1 flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="corrections.{{ $index }}"
                                placeholder="Correct it…"
                                x-on:input="dismissed['qc{{ $index }}'] = true"
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkCorrection,save"
                                class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                            >
                            @unless ($readOnly)
                                <x-check-button method="checkCorrection" :index="$index" key-prefix="qc" wire-target="checkCorrection,save" />
                            @endunless
                        </div>

                        <div x-show="!dismissed['qc{{ $index }}']" x-transition.opacity.duration.300ms>
                            <x-severity-feedback :feedback="$correctionItemFeedback" />
                        </div>
                    </div>
                @endforeach
            </div>
            @error('corrections')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        @unless ($readOnly)
            <x-continue-button
                on-click="filled.forEach((_, i) => dismissed['freq' + i] = true); Object.keys(dismissed).filter(k => k.startsWith('qc')).forEach(k => dismissed[k] = true); $wire.save().then(() => { dismissed = {} })"
                wire-target="checkOne,checkCorrection,save"
                loading-label="Checking your sentences…"
            />
        @endunless
    </div>
</div>
