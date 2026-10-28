<?php

use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\VocabularyWord;
use App\Services\PexelsClient;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Lesson design 3: one task per screen — the episode's cover and hook,
 * then a podcast-style player with one "listen for" line (text closed),
 * then the true/false cards and the detail question one per screen, then
 * an optional second listen with the synced text open. The phrases join
 * My Words by themselves on Finish.
 *
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
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;

    /** Extra words added to the gap-fill word bank beyond the real answers. */
    private const GAP_FILL_DECOYS = 2;

    /** The gap-fill is hidden for now (a pick showed "page expired" on the host), so it never blocks Continue. */
    private const GAP_FILL_HIDDEN = true;

    public MissionRun $run;

    public bool $readOnly = false;

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
     * the same words in the same order: the target phrases plus a couple
     * of decoys (see gapFillDecoys()), so the bank is not simply the
     * answers and the gaps cannot be filled by crossing words off.
     *
     * @var array<int, string>
     */
    public array $gapFillBankOrder = [];

    public function mount(): void
    {
        $this->gapFillBankOrder = collect($this->targetPhrases())
            ->pluck('phrase')
            ->merge($this->gapFillDecoys())
            ->shuffle()
            ->values()
            ->all();

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
    #[Computed]
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
     * [] until that command has been run for this mission. A conversation's
     * chunks come speaker-tagged (`listening_turns`, see
     * missions:align-listening-speakers) so the panel can read as a chat;
     * anything else keeps the plain Whisper segments.
     *
     * @return list<array{text: string, start: float, end: float, speaker?: string}>
     */
    public function listeningSegments(): array
    {
        $listening = $this->run->mission->stepContent('listening');

        return ($listening['listening_turns'] ?? []) ?: ($listening['listening_segments'] ?? []);
    }

    private function targetPhrases(): array
    {
        return $this->run->mission->stepContent('listening')['target_phrases'] ?? [];
    }

    /**
     * Other words from this mission's vocabulary to pad the word bank with.
     * A phrase that actually occurs in the recording is never used: it
     * could be a perfectly good answer to a gap that the exercise would
     * then mark wrong.
     *
     * @return list<string>
     */
    private function gapFillDecoys(): array
    {
        $targets = collect($this->targetPhrases())->pluck('phrase');
        $spoken = mb_strtolower(collect($this->listeningSegments())->pluck('text')->implode(' '));

        return collect($this->run->mission->vocabularyEntries())
            ->pluck('phrase')
            ->unique()
            ->reject(fn (string $phrase) => $targets->contains($phrase) || str_contains($spoken, mb_strtolower($phrase)))
            ->shuffle()
            ->take(self::GAP_FILL_DECOYS)
            ->values()
            ->all();
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, label: string, difficulty?: string}>
     */
    public function comprehensionCards(): array
    {
        $listening = $this->run->mission->stepContent('listening');

        return collect($listening['comprehension_check'] ?? [])
            ->map(fn ($item) => [
                'prompt' => $item['statement'],
                'options' => ['True', 'False'],
                'correct' => $item['correct'] ? 0 : 1,
                'label' => 'True or false?',
                ...(isset($item['difficulty']) ? ['difficulty' => $item['difficulty']] : []),
            ])
            ->all();
    }

    /**
     * Every question after the first listen, one card per screen: the
     * true/false cards, then the detail question (keyed, so its answer is
     * kept as detail_correct).
     *
     * @return list<array<string, mixed>>
     */
    public function questionCards(): array
    {
        $detail = $this->run->mission->stepContent('listening')['detail_question'] ?? null;

        return [
            ...$this->comprehensionCards(),
            ...($detail ? [[
                'prompt' => $detail['question'],
                'options' => $detail['options'],
                'correct' => $detail['correct'],
                'label' => 'A detail',
                'key' => 'detail',
            ]] : []),
        ];
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

        if (self::GAP_FILL_HIDDEN || ! count($phrases)) {
            return true;
        }

        foreach ($phrases as $index => $item) {
            if (($this->gapFillSelections[$index] ?? null) !== $item['phrase']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every pick is checked as it lands — a tap on the select and a pick
     * restored from the browser's draft (x-draft) alike.
     */
    public function updatedGapFillSelections(mixed $selection, int|string $index): void
    {
        $this->checkGapFill((int) $index);
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

        // The episode's phrases join My Words by themselves (lesson design 3).
        $this->initWordsToTrack();
        $this->addWordsToNotebook();

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    /**
     * @return list<array{word: string, meaning: string, example: ?string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->targetPhrases())
            ->map(fn ($item) => [
                'word' => $item['phrase'],
                'meaning' => $item['meaning'] ?? '',
                'example' => VocabularyWord::exampleFromGap($item),
            ])
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
    $questionCards = $readOnly ? [] : $this->questionCards();
    $draftPrefix = $this->draftPrefix();
    $keepsDraft = ! $readOnly && ! $preview;
    $sourceTitle = trim(\Illuminate\Support\Str::afterLast($listening['source'] ?? 'Listening', '—'));
    $sourceTitle = trim(preg_replace('/\s*\(\d{4}\)$/', '', $sourceTitle));
    $sourceName = trim(\Illuminate\Support\Str::before($listening['source'] ?? '', '—'));
    $listenParams = $preview ? null : ['mission_code' => $run->mission->code, 'source' => \App\Models\AudioListen::SOURCE_LISTENING, 'day_number' => 1, 'manual_report' => true];
    // Screens: 0 start · 1 first listen · 2 questions · 3 second listen with the text.
    $questionsScreen = count($questionCards) ? 2 : null;
    $lastScreen = 3;
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-5 pt-2">
        <x-step-intro kind="Listening" :title="$sourceTitle" :image="$this->heroImageUrl" />
        <x-audio-player podcast :url="$listening['audio_url'] ?? null" :segments="$this->listeningSegments()" />
        @if ($detailCorrect !== null && ($listening['detail_question'] ?? null))
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">You {{ $detailCorrect ? 'got' : "didn't quite get" }} this one: {{ $listening['detail_question']['question'] }}</p>
        @endif
    </div>
@else
<div
    x-data="{
        screen: 0,
        quizShare: 0,
        init() {
            @if ($keepsDraft)
                this.screen = window.eosDraft.restoreIndex('{{ $draftPrefix }}screen', 0);
                this.$watch('screen', (v) => window.eosDraft.persistIndex('{{ $draftPrefix }}screen', v));
            @endif
        },
        get share() { return this.screen === 2 ? (2 + this.quizShare) / 4 : (this.screen + 0.5) / 4 },
    }"
    x-effect="$dispatch('step-progress', { share }); if (screen !== 1 && screen !== 3) $el.querySelector('audio')?.pause()"
    x-on:quick-round-progress="quizShare = $event.detail.share"
    x-on:quick-round-answered="if ($event.detail.key === 'detail') $wire.set('detailCorrect', $event.detail.correct, false)"
    x-back-close.step="{ open: screen > 0, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="Listening" :minutes="$run->mission->stepDuration('listening')" :title="$sourceTitle" :image="$this->heroImageUrl" />
        @if (filled($sourceName))
            <p class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $sourceName }}</p>
        @endif

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- One player for both listens: the text stays closed the first time
         and opens by itself the second time. --}}
    <section x-show="screen === 1 || screen === 3" x-cloak class="animate-lesson-screen-in flex min-h-[calc(100dvh-11rem)] flex-col gap-5 pt-2">
        <div class="flex flex-1 flex-col gap-4">
            <span x-show="screen === 3" class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Listen again with the text</span>
            <div x-show="screen === 1" class="flex flex-col gap-4">
                @if ($cover = $this->heroImageUrl)
                    <div class="relative h-40 overflow-hidden rounded-3xl">
                        <img src="{{ $cover }}" alt="" class="h-full w-full object-cover">
                        <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-4 pt-6 pb-3 text-sm font-bold text-white">{{ $sourceTitle }}</span>
                    </div>
                @endif
                @if (filled($listening['hook'] ?? null))
                    <p class="rounded-2xl bg-accent-soft px-4 py-3 text-sm font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">🎯 {{ $listening['hook'] }}</p>
                @endif
            </div>

            <x-audio-player
                podcast
                text-hidden
                text-open-when="screen === 3"
                :url="$listening['audio_url'] ?? null"
                :listen="$listenParams"
                :segments="$this->listeningSegments()"
                :resume-key="$keepsDraft ? $draftPrefix.'position' : null"
            />
        </div>

        <div class="flex flex-col items-stretch gap-3 pb-2">
            <div x-show="screen === 1">
                <x-continue-button on-click="screen = {{ $questionsScreen ?? $lastScreen }}" wire-target="save" />
            </div>
            <div x-show="screen === 3" x-cloak>
                <x-continue-button on-click="$wire.save()" wire-target="save" loading-label="Saving…">Finish</x-continue-button>
            </div>
        </div>
    </section>

    @if ($questionsScreen)
        <section x-show="screen === 2" x-cloak class="animate-lesson-screen-in pt-2">
            <x-quick-round
                lesson
                :cards="$questionCards"
                label="True or false?"
                :draft-key="$keepsDraft ? $draftPrefix.'questions' : null"
                on-complete="if (screen === 2) screen = 3"
                on-skip="screen = 3"
            />
        </section>
    @endif

    @error('gapFill')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror
</div>
@endif
