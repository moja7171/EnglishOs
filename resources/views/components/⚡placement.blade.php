<?php

use App\Models\PlacementTest as PlacementTestResult;
use App\Services\AiFeedbackCard;
use App\Services\GroqClient;
use App\Services\PlacementTest;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The placement test a learner takes right after registering — three
 * parts, about 8-10 minutes: vocabulary recognition, grammar in context,
 * and one real spoken answer. See App\Services\PlacementTest for the
 * content, the scoring, and (importantly) what the result does NOT
 * change: not the curriculum, not the order, not the pace.
 *
 * Skippable on purpose. A gate in front of the very first lesson costs
 * more learners than an unknown starting level does; skipping just
 * leaves the registration form's self-assessment in place and offers the
 * test again from the Missions page.
 */
new class extends Component
{
    use WithFileUploads;

    /** @var array<int, int> item index => chosen option */
    public array $vocabularyAnswers = [];

    /** @var array<int, int> item index => chosen option */
    public array $grammarAnswers = [];

    public $recording;

    public bool $completed = false;

    public ?array $result = null;

    public ?string $transcript = null;

    #[Computed]
    public function test(): PlacementTest
    {
        return app(PlacementTest::class);
    }

    public function answerVocabulary(int $index, int $option): void
    {
        $this->vocabularyAnswers[$index] = $option;
    }

    public function answerGrammar(int $index, int $option): void
    {
        $this->grammarAnswers[$index] = $option;
    }

    /**
     * Grades the spoken answer and writes the result. The recording is
     * transcribed and read for CEFR level; if either AI call fails the
     * result is still saved, marked provisional, from the two objective
     * parts alone — a relay outage must not cost the learner their test.
     */
    public function finish(): void
    {
        $this->validate([
            'vocabularyAnswers' => 'array',
            'grammarAnswers' => 'array',
            'recording' => 'nullable|file|max:20480',
        ]);

        $spokenLevel = null;

        if ($this->recording) {
            [$this->transcript, $spokenLevel] = $this->gradeSpeaking();
        }

        $this->result = $this->test->score($this->vocabularyAnswers, $this->grammarAnswers, $spokenLevel);

        PlacementTestResult::create([
            'learner_id' => auth()->id(),
            'level' => $this->result['level'],
            'recognition_level' => $this->result['recognitionLevel'],
            'spoken_level' => $this->result['spokenLevel'],
            'transcript' => $this->transcript,
            'detail' => [
                'bands' => $this->result['bands'],
                'provisional' => $this->result['provisional'],
                'aboveRange' => $this->result['aboveRange'],
                'vocabularyAnswers' => $this->vocabularyAnswers,
                'grammarAnswers' => $this->grammarAnswers,
            ],
        ]);

        // The real level replaces the registration form's self-assessment
        // — every AI prompt in the app reads it through
        // User::levelDescription().
        auth()->user()->forceFill(['cefr_level' => $this->result['level']])->save();

        $this->completed = true;
    }

    /**
     * @return array{0: ?string, 1: ?string} transcript, CEFR level
     */
    private function gradeSpeaking(): array
    {
        try {
            $transcript = trim(app(GroqClient::class)->transcribe($this->recording->getRealPath()));

            if ($transcript === '') {
                return [null, null];
            }

            $data = app(AiFeedbackCard::class)->generate(
                [['role' => 'user', 'text' => "Transcript of the learner's spoken answer: \"{$transcript}\""]],
                systemPrompt: 'You are a CEFR examiner placing an English learner. They spoke for about 45 '
                    .'seconds about a normal day in their life. Judge their SPOKEN production only — range of '
                    .'vocabulary, control of tenses, and how much they can say without breaking down. Ignore '
                    .'transcription artefacts and pronunciation. Reply with ONLY valid JSON, no markdown fences: '
                    .'{"level": "one of: below A1, A1, A2, B1, above B1", "reason": "one short sentence, '
                    .'addressed to the learner, warm and concrete"}',
                requiredKeys: ['level'],
            );

            return [$transcript, $data['level'] ?? null];
        } catch (Throwable) {
            return [$this->transcript, null];
        }
    }

    public function skip(): void
    {
        $this->redirect(route('home'), navigate: true);
    }

    public function start(): void
    {
        $this->redirect(route('home'), navigate: true);
    }
};
?>

@php
    $vocabulary = $this->test->vocabulary();
    $grammar = $this->test->grammar();
    $speaking = $this->test->speaking();
    $totalObjective = count($vocabulary) + count($grammar);
@endphp

<div class="mx-auto max-w-2xl space-y-6 p-6">
    @if ($completed)
        @php $level = $result['level']; @endphp
        <div class="space-y-5 rounded-2xl border border-line bg-surface p-6 text-center dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Your starting level</p>
            <p class="font-display text-5xl font-extrabold text-accent dark:text-accent-dark">{{ $level }}</p>
            <p class="text-sm text-ink dark:text-ink-dark">{{ \App\Models\User::levelOptions()[$level] ?? $level }}</p>

            @if ($result['provisional'])
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Based on the written parts only — we couldn't listen to your recording just now.</p>
            @elseif ($result['spokenLevel'] && $result['spokenLevel'] !== $result['recognitionLevel'])
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                    You recognise {{ $result['recognitionLevel'] }} English and speak at {{ $result['spokenLevel'] }} — normal, and exactly the gap this app is built to close.
                </p>
            @endif

            @if ($result['aboveRange'])
                <p class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                    You're already past B1. This program is built to take someone to a strong, real B1 — you're welcome to use it, but much of it will feel easy.
                </p>
            @endif

            <div class="rounded-xl bg-surface-sunken p-4 text-left dark:bg-surface-sunken-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">What happens now</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                    Everyone starts at Mission 1 and works through all 24 — that's how the vocabulary and grammar
                    build on each other. Your level changes how the app talks to you, not what you get.
                    The program is 120 days at one day a day; do two in a sitting and it's 60. Your pace, whatever
                    your level.
                </p>
            </div>

            <button
                type="button"
                wire:click="start"
                wire:loading.attr="disabled"
                wire:target="start"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
            >Start Mission 1 @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
        </div>
    @else
        <div
            data-total-objective="{{ $totalObjective }}"
            x-data="{
                part: 0,
                answered: 0,
                totalObjective: 0,
                recorded: false,
                init() { this.totalObjective = Number(this.$el.dataset.totalObjective) },
            }"
            x-on:placement-answered="answered++"
            class="space-y-6"
        >
            <header class="border-b border-line pb-4 dark:border-line-dark">
                <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Where are you starting?</h1>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
                    About 8 minutes, three short parts. It sets how the app talks to you — and gives you something
                    real to measure against when you finish.
                </p>
                <div class="mt-3">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                            :style="`width: ${Math.min((answered / totalObjective) * 100, 100)}%`"
                        ></div>
                        <x-slot:label>
                            <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">
                                Part <span x-text="part + 1"></span> of 3
                            </p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            </header>

            {{-- Part 1 — vocabulary --}}
            <div x-show="part === 0" x-cloak class="space-y-3">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">What does each word mean?</p>
                @foreach ($vocabulary as $index => $item)
                    <div class="rounded-2xl border border-line p-3 dark:border-line-dark">
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $item['word'] }}</p>
                        <div class="mt-2 grid gap-1.5">
                            @foreach ($item['options'] as $option => $text)
                                <button
                                    type="button"
                                    wire:click="answerVocabulary({{ $index }}, {{ $option }})"
                                    x-on:click="if (!$el.dataset.counted) { $el.dataset.counted = 1; $dispatch('placement-answered') }"
                                    @class([
                                        'cursor-pointer rounded-xl border px-3 py-2 text-left text-sm transition-colors' => true,
                                        'border-accent bg-accent-soft font-semibold text-accent-ink dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ($vocabularyAnswers[$index] ?? null) === $option,
                                        'border-line text-ink-soft hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ($vocabularyAnswers[$index] ?? null) !== $option,
                                    ])
                                >{{ $text }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <button
                    type="button"
                    x-on:click="part = 1; window.scrollTo(0, 0)"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Next part @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
            </div>

            {{-- Part 2 — grammar --}}
            <div x-show="part === 1" x-cloak class="space-y-3">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Choose the word that fits.</p>
                @foreach ($grammar as $index => $item)
                    <div class="rounded-2xl border border-line p-3 dark:border-line-dark">
                        <p class="text-sm text-ink dark:text-ink-dark">{{ $item['prompt'] }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($item['options'] as $option => $text)
                                <button
                                    type="button"
                                    wire:click="answerGrammar({{ $index }}, {{ $option }})"
                                    x-on:click="if (!$el.dataset.counted) { $el.dataset.counted = 1; $dispatch('placement-answered') }"
                                    @class([
                                        'cursor-pointer rounded-full border px-3 py-1.5 text-sm transition-colors' => true,
                                        'border-accent bg-accent-soft font-semibold text-accent-ink dark:border-accent-dark dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ($grammarAnswers[$index] ?? null) === $option,
                                        'border-line text-ink-soft hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ($grammarAnswers[$index] ?? null) !== $option,
                                    ])
                                >{{ $text }}</button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" x-on:click="part = 0; window.scrollTo(0, 0)" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Back</button>
                    <button type="button" x-on:click="part = 2; window.scrollTo(0, 0)" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark">Last part @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
                </div>
            </div>

            {{-- Part 3 — the spoken answer --}}
            <div x-show="part === 2" x-cloak class="space-y-4">
                <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Speak for about {{ $speaking['seconds'] }} seconds</p>
                    <p class="mt-1 text-lg font-semibold text-ink dark:text-ink-dark">{{ $speaking['prompt'] }}</p>
                    <ul class="mt-2 list-disc space-y-0.5 pl-5 text-sm text-ink-soft dark:text-ink-soft-dark">
                        @foreach ($speaking['bullets'] as $bullet)
                            <li>{{ $bullet }}</li>
                        @endforeach
                    </ul>
                    <p class="mt-3 text-xs text-ink-faint dark:text-ink-faint-dark">
                        Say as much as you can, in your own words. Mistakes are fine — they're what we're measuring.
                    </p>
                    <div class="mt-3" x-on:click="recorded = true">
                        <x-voice-recorder field="recording" :file="$recording" file-name="placement.webm" />
                    </div>
                </div>

                <x-continue-button
                    on-click="$wire.finish()"
                    wire-target="finish"
                    loading-label="Listening to your answer…"
                    ready-when="recorded"
                    hint="Record your answer to finish"
                />
            </div>

            <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">
                <button type="button" wire:click="skip" class="cursor-pointer underline hover:text-ink dark:hover:text-ink-dark">Skip for now</button>
                — you can take it later from the Missions page.
            </p>
        </div>
    @endif
</div>
