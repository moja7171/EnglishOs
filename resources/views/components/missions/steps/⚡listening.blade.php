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

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a recap of the target expressions before the
     * learner actually navigates on, instead of jumping away immediately.
     */
    public bool $completed = false;

    /** @var array<int, string> */
    public array $gistPoints = ['', '', ''];

    /** @var array<int, string> */
    public array $expressionsHeard = ['', '', ''];

    /** @var array<string, array{severity: string, hint: string, checkedText: string}> keyed by field key */
    public array $feedback = [];

    /** @var array<string, string> keyed by field key — per-input check failure message */
    public array $checkErrors = [];

    public function mount(): void
    {
        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('listening')?->content_ref ?? '{}', true);

        $this->gistPoints = array_pad($data['gist_points'] ?? [], 3, '');
        $this->expressionsHeard = array_pad($data['expressions_heard'] ?? [], 3, '');
    }

    public function checkGist(int $index): void
    {
        $text = trim($this->gistPoints[$index] ?? '');

        if ($text === '') {
            return;
        }

        $this->runCheck("gist_{$index}", $this->gistContext(), $text);
    }

    public function checkExpression(int $index): void
    {
        $text = trim($this->expressionsHeard[$index] ?? '');

        if ($text === '') {
            return;
        }

        $this->runCheck("expr_{$index}", $this->expressionContext(), $text);
    }

    /**
     * A faithful summary of the real transcript (seeded per mission) so the
     * AI check can catch an answer that is fluent English but unrelated to
     * what was actually said, not just judge grammar in isolation.
     */
    private function topicSummary(): ?string
    {
        return $this->run->mission->stepContent('listening')['topic_summary'] ?? null;
    }

    private function gistContext(): string
    {
        $context = 'a complete English sentence describing one thing the learner understood from a B1-level listening';

        return $this->topicSummary() ? "{$context}. The listening was about: {$this->topicSummary()}" : $context;
    }

    private function expressionContext(): string
    {
        $context = 'a personal sentence using an expression the learner heard in a B1-level listening';

        return $this->topicSummary() ? "{$context}. The listening was about: {$this->topicSummary()}" : $context;
    }

    /**
     * Asks the shared SentenceChecker to judge one field, generalized with
     * a per-field context instead of a single target word, plus a
     * topic-relevance judgment grounded in the real transcript summary.
     * Verdict is tagged with the exact text it applies to, so a later edit
     * doesn't leave a stale verdict attached to different text. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(string $key, string $context, string $text): void
    {
        unset($this->checkErrors[$key]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether what the learner wrote is a genuine, natural, complete English '
                    .'sentence about the SAME GENERAL TOPIC as the listening (not just a bare word or fragment, '
                    .'and not about a completely different topic). This is a coarse topic check only — do NOT '
                    .'fact-check specific details against the topic summary (who said what, exact opinions, '
                    .'etc.); the summary is background, not a source to grade accuracy against.',
                majorCriteria: 'it is just a bare word or fragment (not a real sentence), it is about a '
                    .'completely different topic than the listening',
                context: $context,
                text: $text,
                extraGuidance: 'Treat anything on-topic and correctly formed as "none", even if a small detail '
                    .'is debatable — never claim the learner\'s facts are wrong, since you were only given a '
                    .'short summary, not the full listening.',
            );

            $this->feedback[$key] = $data + ['checkedText' => $text];
        } catch (ConnectionException) {
            $this->checkErrors[$key] = "Couldn't reach the AI service — please try again.";
        } catch (\Throwable $e) {
            $this->checkErrors[$key] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    public function save(): void
    {
        $gist = collect($this->gistPoints)->map(fn ($p) => trim($p))->filter();

        if ($gist->count() < 3) {
            $this->addError('gistPoints', 'Write all 3 things you understood before continuing.');

            return;
        }

        $entries = collect();

        foreach ($this->gistPoints as $index => $text) {
            $text = trim($text);
            if ($text !== '') {
                $entries->push(['key' => "gist_{$index}", 'context' => $this->gistContext(), 'text' => $text]);
            }
        }

        foreach ($this->expressionsHeard as $index => $text) {
            $text = trim($text);
            if ($text !== '') {
                $entries->push(['key' => "expr_{$index}", 'context' => $this->expressionContext(), 'text' => $text]);
            }
        }

        // Every filled sentence needs a fresh Gemini verdict before Continue
        // is allowed through — reuse an existing one only if it was checked
        // against this exact text (an edit since the last check invalidates it).
        foreach ($entries as $entry) {
            $alreadyChecked = ($this->feedback[$entry['key']]['checkedText'] ?? null) === $entry['text'];

            if (! $alreadyChecked) {
                $this->runCheck($entry['key'], $entry['context'], $entry['text']);
            }
        }

        $hasMajorIssue = $entries->contains(
            fn ($entry) => ($this->feedback[$entry['key']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('sentences', 'Fix the highlighted sentence before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gist_points' => $gist->values(),
                'expressions_heard' => collect($this->expressionsHeard)->map(fn ($e) => trim($e))->filter()->values(),
            ]),
        ]);

        // Progress is already saved — this only decides what the learner sees
        // next: the language recap, which they dismiss with proceed() below.
        $this->completed = true;
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }
};
?>

@php
    $listening = $run->mission->stepContent('listening');
    $targetPhrases = $listening['target_phrases'] ?? [];
    $initialGistFilled = collect($gistPoints)->map(fn ($p) => trim($p) !== '')->values();
@endphp

<div
    class="space-y-6"
    x-data="{
        dismissed: {},
        gistFilled: {{ $initialGistFilled->toJson() }},
        get gistDone() { return this.gistFilled.filter(Boolean).length === 3 },
    }"
>
    <x-hook :text="$listening['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">{{ $listening['source'] ?? 'Listening' }}</p>
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" />
        </div>
    </div>

    @if ($completed)
        <div class="space-y-4 rounded-lg border border-neutral-300 p-4 dark:border-neutral-700">
            <div>
                <p class="text-xs font-semibold tracking-wide text-green-600 uppercase">✓ Listening complete</p>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-400">Here's the language from today's episode — take a second look before moving on.</p>
            </div>
            <dl class="space-y-2">
                @foreach ($targetPhrases as $item)
                    <div>
                        <dt class="text-sm font-bold">{{ $item['phrase'] }}</dt>
                        <dd class="text-xs text-neutral-500">{{ $item['meaning'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <button
                wire:click="proceed"
                class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
            >
                Continue
            </button>
        </div>
    @else
    <div wire:loading.class="pointer-events-none" wire:target="checkGist,checkExpression,save">
        <div>
            <p class="text-sm font-semibold">First listening — gist</p>
            <p class="text-xs text-neutral-500">Listen without the transcript. What is the conversation about? Write 3 full sentences about what you understood. Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            <div class="mt-2 space-y-2">
                @foreach ($gistPoints as $index => $point)
                    @php $key = "gist_{$index}"; $itemFeedback = $feedback[$key] ?? null; @endphp
                    <div>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                wire:model="gistPoints.{{ $index }}"
                                placeholder="Sentence {{ $index + 1 }}…"
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkGist,checkExpression,save"
                                x-on:input="dismissed['{{ $key }}'] = true; gistFilled[{{ $index }}] = $el.value.trim() !== ''"
                                class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                            >
                            @unless ($readOnly)
                                <button
                                    type="button"
                                    x-on:click="dismissed['{{ $key }}'] = true; $wire.checkGist({{ $index }}).then(() => { dismissed['{{ $key }}'] = false })"
                                    wire:loading.attr="disabled"
                                    wire:target="checkGist,checkExpression,save"
                                    class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                >
                                    <span wire:loading.remove wire:target="checkGist({{ $index }})">Check</span>
                                    <span wire:loading wire:target="checkGist({{ $index }})">Checking…</span>
                                </button>
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkGist({{ $index }})" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['{{ $key }}']" x-transition.opacity.duration.300ms>
                            @if ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'major')
                                <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900 dark:bg-red-950">
                                    <p class="text-sm text-red-700 dark:text-red-400">{{ $itemFeedback['hint'] }}</p>
                                </div>
                            @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'minor')
                                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950">
                                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ $itemFeedback['hint'] }}</p>
                                </div>
                            @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'none')
                                <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-green-900 dark:bg-green-950">
                                    <p class="text-sm text-green-700 dark:text-green-400">Looks good</p>
                                </div>
                            @endif
                            @if ($checkErrors[$key] ?? null)
                                <p class="mt-1 text-xs text-red-600">{{ $checkErrors[$key] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @error('gistPoints')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div
            class="mt-6 transition-opacity duration-300"
            @unless ($readOnly)
                :class="gistDone ? '' : 'pointer-events-none opacity-40'"
            @endunless
        >
            <p class="text-sm font-semibold">Second listening — useful expressions</p>
            <p class="text-xs text-neutral-500">Write a full sentence using each expression you heard.</p>
            @unless ($readOnly)
                <p x-show="!gistDone" class="mt-1 text-xs text-neutral-400">🔒 Finish the first listening above to unlock this.</p>
                @if (count($targetPhrases))
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($targetPhrases as $item)
                            <button
                                type="button"
                                title="{{ $item['meaning'] }}"
                                x-on:click="
                                    let idx = $wire.expressionsHeard.findIndex(v => !v || v.trim() === '');
                                    if (idx === -1) idx = 0;
                                    dismissed['expr_' + idx] = true;
                                    $wire.set('expressionsHeard.' + idx, '{{ ucfirst($item['phrase']) }}');
                                    $nextTick(() => $refs['expr_input_' + idx]?.focus());
                                "
                                class="cursor-pointer rounded-full border border-neutral-300 px-2.5 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
                            >{{ $item['phrase'] }}</button>
                        @endforeach
                    </div>
                @endif
            @endunless
            <div class="mt-2 space-y-2">
                @foreach ($expressionsHeard as $index => $expression)
                    @php $key = "expr_{$index}"; $itemFeedback = $feedback[$key] ?? null; @endphp
                    <div>
                        <div class="flex items-center gap-2">
                            <input
                                type="text"
                                x-ref="expr_input_{{ $index }}"
                                wire:model="expressionsHeard.{{ $index }}"
                                placeholder="Sentence {{ $index + 1 }}…"
                                @readonly($readOnly)
                                wire:loading.attr="disabled"
                                wire:target="checkGist,checkExpression,save"
                                x-on:input="dismissed['{{ $key }}'] = true"
                                class="w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm disabled:opacity-50 dark:border-neutral-700"
                            >
                            @unless ($readOnly)
                                <button
                                    type="button"
                                    x-on:click="dismissed['{{ $key }}'] = true; $wire.checkExpression({{ $index }}).then(() => { dismissed['{{ $key }}'] = false })"
                                    wire:loading.attr="disabled"
                                    wire:target="checkGist,checkExpression,save"
                                    class="shrink-0 cursor-pointer rounded border border-neutral-300 px-2 py-1 text-xs text-neutral-600 transition-colors hover:border-neutral-400 hover:bg-neutral-100 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-neutral-700 dark:text-neutral-400 dark:hover:bg-neutral-800"
                                >
                                    <span wire:loading.remove wire:target="checkExpression({{ $index }})">Check</span>
                                    <span wire:loading wire:target="checkExpression({{ $index }})">Checking…</span>
                                </button>
                            @endunless
                        </div>

                        @unless ($readOnly)
                            <x-ai-thinking wire:loading wire:target="checkExpression({{ $index }})" class="mt-2" />
                        @endunless

                        <div x-show="!dismissed['{{ $key }}']" x-transition.opacity.duration.300ms>
                            @if ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'major')
                                <div class="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 dark:border-red-900 dark:bg-red-950">
                                    <p class="text-sm text-red-700 dark:text-red-400">{{ $itemFeedback['hint'] }}</p>
                                </div>
                            @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'minor')
                                <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950">
                                    <p class="text-sm text-amber-700 dark:text-amber-400">{{ $itemFeedback['hint'] }}</p>
                                </div>
                            @elseif ($itemFeedback && ($itemFeedback['severity'] ?? 'none') === 'none')
                                <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 dark:border-green-900 dark:bg-green-950">
                                    <p class="text-sm text-green-700 dark:text-green-400">Looks good</p>
                                </div>
                            @endif
                            @if ($checkErrors[$key] ?? null)
                                <p class="mt-1 text-xs text-red-600">{{ $checkErrors[$key] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    @error('sentences')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @unless ($readOnly)
        <button
            x-on:click="['gist_0','gist_1','gist_2','expr_0','expr_1','expr_2'].forEach(k => dismissed[k] = true); $wire.save().then(() => { dismissed = {} })"
            wire:loading.attr="disabled"
            wire:target="checkGist,checkExpression,save"
            class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            <span wire:loading.remove wire:target="save">Continue</span>
            <span wire:loading wire:target="save">Checking your sentences…</span>
        </button>
    @endunless
    @endif
</div>
