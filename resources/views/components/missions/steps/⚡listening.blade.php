<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use Livewire\Component;

/**
 * Mission structure redesign, Epic C: day 1's Listening is now exactly 2
 * clean sub-steps — first listen + a short true/false check (comprehension
 * only; deliberately no shadowing here, so the very first listen isn't
 * competing with a recording task), and a second listen + a gap-fill
 * answered by PICKING from a word bank (not typing). The old gist/
 * expression free-writing (6 AI-checked sentences) is gone entirely — that
 * overlapped with what Vocabulary Builder and Writing already do, and
 * buried Listening's own point (comprehension) under a writing exercise.
 * Shadowing moved to Listen Again (daily_listen_2/3/4, see
 * DailyListenStep) — a real, auto-pausing player, not a good fit for the
 * very first exposure to the audio.
 */
new class extends Component
{
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;

    public MissionRun $run;

    public bool $readOnly = false;

    /**
     * True once Continue has passed every check and Evidence is saved —
     * the step then shows a recap of the target expressions before the
     * learner actually navigates on, instead of jumping away immediately.
     */
    public bool $completed = false;

    /**
     * A one-tap bonus check (see <x-quick-round>), not a required field —
     * null until the learner taps an option (or leaves it alone entirely,
     * which is fine; the round is always skippable). Set client-side by
     * the Quick Round's on-complete Alpine statement.
     */
    public ?bool $detailCorrect = null;

    /** @var array<int, ?string> the phrase the learner picked for each gap, keyed by target_phrases index */
    public array $gapFillSelections = [];

    /** @var array<int, array{severity: string, hint: string}> local verdicts for gapFillSelections, keyed by index */
    public array $gapFillFeedback = [];

    /**
     * The word bank order for the gap-fill sub-step — shuffled once in
     * mount() (not re-shuffled on every render, or the options would jump
     * around under the learner's cursor) so every gap's <select> lists
     * the same target phrases in the same order.
     *
     * @var array<int, string>
     */
    public array $gapFillBankOrder = [];

    public function mount(): void
    {
        $this->gapFillBankOrder = collect($this->targetPhrases())->pluck('phrase')->shuffle()->values()->all();

        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence('listening')?->content_ref ?? '{}', true);

        $this->gapFillSelections = $data['gap_fill_selections'] ?? [];
        $this->detailCorrect = $data['detail_correct'] ?? null;
    }

    /**
     * The episode's cover image — same dual-coding, fetch-once-cache-
     * forever principle as every other PexelsClient call (see
     * ⚡mission-brief.blade.php's heroImageUrl()). Purely decorative,
     * fails soft (null) on no query/no key/any error.
     */
    public function heroImageUrl(): ?string
    {
        $query = $this->run->mission->stepContent('listening')['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($this->run->mission->code.'-listening', $query);
    }

    /**
     * Real Whisper segments (text + start/end seconds) driving the synced
     * text panel below the player — see missions:cache-shadow-timestamps.
     * [] until that command has been run for this mission.
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    public function listeningSegments(): array
    {
        return $this->run->mission->stepContent('listening')['listening_segments'] ?? [];
    }

    private function targetPhrases(): array
    {
        return $this->run->mission->stepContent('listening')['target_phrases'] ?? [];
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function comprehensionCards(): array
    {
        $listening = $this->run->mission->stepContent('listening');

        return collect($listening['comprehension_check'] ?? [])
            ->map(fn ($item) => [
                'prompt' => $item['statement'],
                'options' => ['True', 'False'],
                'correct' => $item['correct'] ? 0 : 1,
                ...(isset($item['difficulty']) ? ['difficulty' => $item['difficulty']] : []),
            ])
            ->all();
    }

    /**
     * Every gap answered from the word bank, correctly — the old free-typed
     * gap-fill was optional bonus practice (a "minor" verdict at worst);
     * now that it's a real sub-step of its own instead of a wrap-up
     * extra, it needs to actually be right before Continue on this
     * sub-step is enabled.
     */
    public function gapFillAllCorrect(): bool
    {
        $phrases = $this->targetPhrases();

        if (! count($phrases)) {
            return true;
        }

        foreach ($phrases as $index => $item) {
            if (($this->gapFillSelections[$index] ?? null) !== $item['phrase']) {
                return false;
            }
        }

        return true;
    }

    public function checkGapFill(int $index): void
    {
        $phrases = $this->targetPhrases();
        $target = $phrases[$index]['phrase'] ?? null;
        $selection = $this->gapFillSelections[$index] ?? null;

        if (! $target || ! $selection) {
            return;
        }

        $this->gapFillFeedback[$index] = $selection === $target
            ? ['severity' => 'none', 'hint' => '']
            : ['severity' => 'minor', 'hint' => 'Not quite — try the word you actually heard in that spot.'];
    }

    public function save(): void
    {
        if (! $this->gapFillAllCorrect()) {
            $this->addError('gapFill', 'Finish the gap-fill correctly before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'listening',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'gap_fill_selections' => $this->gapFillSelections,
                // Optional bonus — saved if attempted, never required.
                'detail_correct' => $this->detailCorrect,
            ]),
        ]);

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->completed = true;
        $this->initWordsToTrack();
    }

    public function proceed(): void
    {
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * @return list<array{word: string, meaning: string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->targetPhrases())
            ->map(fn ($item) => ['word' => $item['phrase'], 'meaning' => $item['meaning'] ?? ''])
            ->values()
            ->all();
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:listening:";
    }
};
?>

@php
    $listening = $run->mission->stepContent('listening');
    $targetPhrases = $listening['target_phrases'] ?? [];
    $detailQuestion = $listening['detail_question'] ?? null;

    $detailCard = $detailQuestion ? [[
        'prompt' => $detailQuestion['question'],
        'options' => $detailQuestion['options'],
        'correct' => $detailQuestion['correct'],
    ]] : [];

    $comprehensionCards = ! $readOnly ? $this->comprehensionCards() : [];
    $hasComprehensionCheck = count($comprehensionCards) > 0;
    $comprehensionIndex = $hasComprehensionCheck ? 0 : null;
    $gapFillIndex = $hasComprehensionCheck ? 1 : 0;
    $totalSubsteps = $gapFillIndex + 1;
    $nextDisabledExpr = "(activeSubstep === {$gapFillIndex} && ".($this->gapFillAllCorrect() ? 'false' : 'true').')';
@endphp

<div class="space-y-6" x-data="{ activeSubstep: 0 }">
    @if ($imageUrl = $this->heroImageUrl())
        <img src="{{ $imageUrl }}" alt="" class="h-32 w-full rounded-2xl object-cover">
    @endif

    <x-hook :text="$listening['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $listening['source'] ?? 'Listening' }}</p>
        @unless ($readOnly)
            <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">Try listening first without reading — the text below is there if you need it.</p>
        @endunless
        <div class="mt-2">
            <x-audio-player :url="$listening['audio_url'] ?? null" on-ended="$dispatch('audio-ended')" :segments="$this->listeningSegments()" />
        </div>
    </div>

    @if ($completed)
        <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <div>
                <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-4 w-4')
                    Listening complete
                </p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Here's the language from today's episode — pick which ones join your spaced-repetition notebook.</p>
            </div>
            <div class="space-y-2">
                @foreach ($targetPhrases as $index => $item)
                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                        <input
                            type="checkbox"
                            wire:model="wordsToTrack.{{ $index }}"
                            class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                        >
                        <span>
                            <span class="block text-sm font-bold text-ink dark:text-ink-dark">{{ $item['phrase'] }}</span>
                            <span class="block text-xs text-ink-faint dark:text-ink-faint-dark">{{ $item['meaning'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @if ($trackedWords)
                    <span class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">
                        @svg('heroicon-o-check-circle', 'h-4 w-4') Added to My Words
                    </span>
                @else
                    <button
                        type="button"
                        wire:click="addWordsToNotebook"
                        wire:loading.attr="disabled"
                        wire:target="addWordsToNotebook"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="addWordsToNotebook" class="inline-flex items-center gap-1 whitespace-nowrap">@svg('heroicon-o-book-open', 'h-4 w-4') Add to My Words</span>
                        <span wire:loading wire:target="addWordsToNotebook">Adding…</span>
                    </button>
                @endif

                <button
                    wire:click="proceed"
                    wire:loading.attr="disabled"
                    wire:target="proceed"
                    class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="proceed">Continue</span>
                    <span wire:loading wire:target="proceed">Please wait…</span>
                </button>
            </div>
        </div>
    @else
    <div wire:loading.class="pointer-events-none" wire:target="checkGapFill,save">
        <div class="mb-4">
            <x-progress-bar>
                <div
                    class="h-full rounded-full bg-accent transition-all duration-300 dark:bg-accent-dark"
                    :style="`width: ${(activeSubstep + 1) / {{ $totalSubsteps }} * 100}%`"
                ></div>
                <x-slot:label>
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                        Part <span x-text="activeSubstep + 1"></span> of {{ $totalSubsteps }}
                    </p>
                </x-slot:label>
            </x-progress-bar>
        </div>

        @if ($hasComprehensionCheck)
            {{-- Sub-step 1: first listen + a short true/false check. --}}
            <div x-show="activeSubstep === {{ $comprehensionIndex }}" x-cloak>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">First listening — quick check</p>
                <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Listen once, then a few quick true/false taps about what you heard.</p>
                <div class="mt-2">
                    <x-quick-round :cards="$comprehensionCards" />
                </div>
            </div>
        @endif

        {{-- Sub-step 2: second listen + gap-fill, answered by picking from a word bank. --}}
        <div x-show="activeSubstep === {{ $gapFillIndex }}" x-cloak>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Second listening — fill the gaps</p>
            <p class="text-xs text-ink-soft dark:text-ink-soft-dark">Listen again, then pick the word you heard in each gap from the word bank below.</p>

            @if (count($targetPhrases))
                <div class="mt-3 flex flex-wrap gap-1.5">
                    @foreach ($this->gapFillBankOrder as $bankPhrase)
                        <span class="rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $bankPhrase }}</span>
                    @endforeach
                </div>

                <div class="mt-3 space-y-3">
                    @foreach ($targetPhrases as $index => $item)
                        @php $gapFeedback = $gapFillFeedback[$index] ?? null; @endphp
                        <div>
                            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                                {{ $item['gap_before'] ?? '' }}
                                <select
                                    wire:model.live="gapFillSelections.{{ $index }}"
                                    @disabled($readOnly)
                                    wire:change="checkGapFill({{ $index }})"
                                    class="inline rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                                >
                                    <option value="">…</option>
                                    @foreach ($this->gapFillBankOrder as $bankPhrase)
                                        <option value="{{ $bankPhrase }}">{{ $bankPhrase }}</option>
                                    @endforeach
                                </select>
                                {{ $item['gap_after'] ?? '' }}
                            </p>
                            @if ($gapFeedback)
                                <p class="mt-1 text-xs {{ $gapFeedback['severity'] === 'none' ? 'text-success dark:text-success-dark' : 'text-amber-600' }}">
                                    @if ($gapFeedback['severity'] === 'none')
                                        @svg('heroicon-o-check-circle', 'inline h-3.5 w-3.5') That's it.
                                    @else
                                        {{ $gapFeedback['hint'] }}
                                    @endif
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                @error('gapFill')
                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endif

            @if ($detailQuestion)
                <div class="mt-4">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Bonus — a detail</p>
                    @if ($readOnly)
                        @if ($detailCorrect !== null)
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
                                You {{ $detailCorrect ? 'got' : "didn't quite get" }} this one: {{ $detailQuestion['question'] }}
                            </p>
                        @endif
                    @else
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Optional — one real detail from the conversation. Doesn't affect Continue.</p>
                        <div class="mt-2">
                            <x-quick-round :cards="$detailCard" on-complete="$wire.set('detailCorrect', correctCount === 1)" />
                        </div>
                    @endif
                </div>
            @endif

            @if ($listening['topic_summary'] ?? null)
                <div class="mt-4">
                    <x-practice-with-friend
                        :text="$listening['topic_summary']"
                        intro="Hey — want to discuss this listening topic with me:"
                        label="Discuss this with a friend"
                    />
                </div>
            @endif

            @unless ($readOnly)
                <div class="mt-4">
                    <x-continue-button
                        on-click="$wire.save()"
                        wire-target="checkGapFill,save"
                        loading-label="Saving…"
                        ready-when="{{ $this->gapFillAllCorrect() ? 'true' : 'false' }}"
                        hint="Finish the gap-fill to continue"
                    />
                </div>
            @endunless
        </div>
    </div>

    <div class="mt-4">
        <x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" :next-disabled="$nextDisabledExpr" />
    </div>
    @endif
</div>
