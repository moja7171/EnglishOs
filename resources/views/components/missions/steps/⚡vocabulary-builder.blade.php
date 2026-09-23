<?php

use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Services\PexelsClient;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;

/**
 * One day's worth of vocabulary (5-6 curated words — never the old
 * "pick at least 8" free-for-all) — this same component renders as
 * vocabulary_builder_1, _2 and _3 (see ⚡runner.blade.php's stepComponents()
 * and the extra 'stepKey' prop it passes only to this family of steps),
 * one per mission day. Each day's words are fixed by the mission's own
 * seeded content, not chosen by the learner — the only choice left is,
 * at the end, which of today's words join the spaced-repetition notebook
 * (see TracksVocabularyNotebook). Part of the mission structure redesign
 * (Epic B).
 */
new class extends Component
{
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;

    public MissionRun $run;

    public bool $readOnly = false;

    /** e.g. 'vocabulary_builder_2' — which day of the 3 this instance is. */
    public string $stepKey = 'vocabulary_builder_1';

    /**
     * True once the learner has moved into practice at least once — after
     * this, the spiral-review/story phases stay reachable via "show again"
     * links, but the phase machine defaults straight back to practice on
     * re-render so a Check click doesn't bounce them back to page 1.
     */
    public bool $practiceStarted = false;

    /** @var array<int, string> one example per word, same order as words() */
    public array $examples = [];

    /** @var array<string, array{severity: string, hint: string}> keyed by word */
    public array $feedback = [];

    /** @var array<string, string> keyed by word — per-input check failure message */
    public array $checkErrors = [];

    /**
     * True once save() has succeeded — swaps the whole step over to the
     * completion recap (pick which words join My Words, then Continue).
     */
    public bool $completed = false;

    public function mount(): void
    {
        $this->examples = array_fill(0, count($this->words()), '');

        if (! $this->readOnly) {
            return;
        }

        $data = json_decode($this->run->latestEvidence($this->stepKey)?->content_ref ?? '{}', true);
        $saved = collect($data['examples'] ?? [])->keyBy('word');

        foreach ($this->words() as $index => $word) {
            $this->examples[$index] = $saved[$word['phrase']]['example'] ?? '';
        }
    }

    /** 1, 2, or 3 — parsed from the step key rather than passed twice. */
    public function dayNumber(): int
    {
        return (int) substr($this->stepKey, -1);
    }

    /**
     * @return list<array{phrase: string, meaning: string, pos?: string, synonym?: string, example?: string, image_query?: string, difficulty?: string}>
     */
    public function words(): array
    {
        return $this->run->mission->stepContent($this->stepKey)['words'] ?? [];
    }

    /**
     * Yesterday's words, for the spiral-review warm-up (see
     * spiralReviewCards()) — null on day 1, which has no "yesterday"
     * inside this mission.
     */
    private function previousDayWords(): ?array
    {
        $day = $this->dayNumber();

        if ($day <= 1) {
            return null;
        }

        return $this->run->mission->stepContent('vocabulary_builder_'.($day - 1))['words'] ?? null;
    }

    /**
     * Splits the day's story on **word** markers into ordered plain-text/
     * highlighted-phrase segments, each highlighted segment carrying its
     * own meaning right alongside it — the template shows that meaning
     * inline, never behind a hover tooltip (which never worked on mobile
     * — see the mission structure redesign notes).
     *
     * @return list<array{type: string, value: string, meaning?: string}>
     */
    public function storySegments(): array
    {
        $story = $this->run->mission->stepContent($this->stepKey)['story'] ?? '';
        $parts = preg_split('/\*\*(.+?)\*\*/', $story, -1, PREG_SPLIT_DELIM_CAPTURE);

        return collect($parts)
            ->map(fn ($part, $i) => $i % 2 === 0
                ? ['type' => 'text', 'value' => $part]
                : ['type' => 'word', 'value' => $part])
            ->filter(fn ($segment) => $segment['value'] !== '')
            ->values()
            ->all();
    }

    /**
     * A picture flashcard for concrete-noun words only, used by
     * imageMatchCards() below — see EOS-009 §8. Null (no image) for every
     * abstract word never authored with an image_query, and also null on
     * any fetch failure — dual-coding is a nice-to-have, never something
     * worth an error state. Deliberately NOT shown on the personal-example
     * practice cards any more (Epic B decision) — this method now exists
     * only to feed the image-match quiz round.
     */
    private function wordImageUrl(string $phrase): ?string
    {
        $word = collect($this->words())->firstWhere('phrase', $phrase);
        $query = $word['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($phrase, $query, null);
    }

    /**
     * The spiral-review warm-up (Epic B decision) — a quick, ungraded
     * meaning-match round on YESTERDAY's words, shown before today's new
     * ones are introduced (days 2 and 3 only). Distractors drawn from
     * today's own words so they're always plausible, never random.
     *
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function spiralReviewCards(): array
    {
        $previous = $this->previousDayWords();

        if (! $previous) {
            return [];
        }

        $today = collect($this->words());

        return collect($previous)
            ->map(function (array $entry) use ($today) {
                $distractors = $today->pluck('meaning')->filter()->shuffle()->take(2);
                $options = collect([$entry['meaning'], ...$distractors])->shuffle()->values();

                return [
                    'prompt' => $entry['phrase'],
                    'options' => $options->all(),
                    'correct' => $options->search($entry['meaning']),
                    ...(isset($entry['difficulty']) ? ['difficulty' => $entry['difficulty']] : []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A warm-up <x-quick-round> between the story and practice — one card
     * per word, distractor meanings pulled from the OTHER words in this
     * same day so they're always plausible-sounding.
     *
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function meaningCheckCards(): array
    {
        $words = collect($this->words());

        return $words
            ->map(function (array $entry) use ($words) {
                $distractors = $words
                    ->where('phrase', '!=', $entry['phrase'])
                    ->pluck('meaning')
                    ->filter()
                    ->shuffle()
                    ->take(2);

                $options = collect([$entry['meaning'], ...$distractors])->shuffle()->values();

                return [
                    'prompt' => $entry['phrase'],
                    'options' => $options->all(),
                    'correct' => $options->search($entry['meaning']),
                    ...(isset($entry['difficulty']) ? ['difficulty' => $entry['difficulty']] : []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * A second, image-based warm-up round right after the meaning-check
     * one — "which picture means this word?" (kept from before the Epic B
     * redesign; only the personal-example practice cards below lost their
     * images, not this quiz). Only covers words with an image_query.
     *
     * @return list<array{prompt: string, options: list<string>, correct: int, optionType: string}>
     */
    public function imageMatchCards(): array
    {
        $words = collect($this->words());
        $imageWords = $words->filter(fn ($w) => $w['image_query'] ?? null);
        $client = app(PexelsClient::class);

        return $words
            ->filter(fn (array $entry) => $entry['image_query'] ?? null)
            ->filter(fn (array $entry) => $imageWords->where('phrase', '!=', $entry['phrase'])->count() >= 2)
            ->map(function (array $entry) use ($imageWords, $client) {
                $correctImage = $client->imageUrlFor($entry['phrase'], $entry['image_query'], null);

                if (! $correctImage) {
                    return null;
                }

                $distractorImages = $imageWords
                    ->where('phrase', '!=', $entry['phrase'])
                    ->shuffle()
                    ->take(2)
                    ->map(fn ($w) => $client->imageUrlFor($w['phrase'], $w['image_query'], null))
                    ->filter();

                if ($distractorImages->count() < 2) {
                    return null;
                }

                $options = collect([$correctImage, ...$distractorImages])->shuffle()->values();

                return [
                    'prompt' => $entry['phrase'],
                    'options' => $options->all(),
                    'correct' => $options->search($correctImage),
                    'optionType' => 'image',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function startPractice(): void
    {
        $this->practiceStarted = true;
    }

    public function checkOne(int $index): void
    {
        $word = $this->words()[$index]['phrase'] ?? null;

        if (! $word) {
            return;
        }

        $example = trim($this->examples[$index] ?? '');

        if ($example === '') {
            $this->checkErrors[$word] = 'Write something first.';

            return;
        }

        $this->runCheck($word, $example);
    }

    /**
     * Asks the shared SentenceChecker to judge one word/sentence pair. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(string $word, string $example): void
    {
        unset($this->checkErrors[$word]);

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner used the target word correctly, naturally, and as a '
                    .'genuine personal sentence (not just repeating the dictionary definition).',
                majorCriteria: 'the word is missing or used with the wrong meaning, the sentence just repeats '
                    .'the definition',
                context: "a personal sentence using the word \"{$word}\"",
                text: $example,
                extraGuidance: $this->run->aiToneGuidance(),
                feedbackDepth: $this->run->mission->feedbackDepth(),
            );
            $this->recordGeminiCall();

            $this->feedback[$word] = $data + ['checkedText' => $example];
            $this->trackCheckAttempt($word, $data['severity']);
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$word] = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkErrors[$word] = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    /**
     * After 3 failed attempts on the same word, the learner can ask the AI
     * to just write the corrected sentence — see TracksCheckAttempts.
     */
    public function revealCorrection(int $index): void
    {
        $word = $this->words()[$index]['phrase'] ?? null;
        $example = trim($this->examples[$index] ?? '');

        if (! $word || $example === '') {
            return;
        }

        $this->revealCorrectionFor(
            key: $word,
            context: "a personal sentence using the word \"{$word}\"",
            text: $example,
            errorBagKey: $word,
            onCorrected: function (string $corrected) use ($word, $index) {
                $this->examples[$index] = $corrected;
                $this->feedback[$word] = ['severity' => 'none', 'hint' => '', 'checkedText' => $corrected];
            },
        );
    }

    public function declineReveal(int $index): void
    {
        $word = $this->words()[$index]['phrase'] ?? null;

        if ($word) {
            $this->declineCheckReveal($word);
        }
    }

    public function save(): void
    {
        $words = $this->words();

        $filled = collect($this->examples)
            ->filter(fn ($example) => trim((string) $example) !== '')
            ->map(fn ($example, $index) => [
                'word' => $words[$index]['phrase'] ?? null,
                'example' => trim($example),
            ])
            ->values();

        // Every one of today's (5-6) words needs its own example — small
        // and curated enough now that there's no "just 3 is fine" bar.
        if ($filled->count() < count($words)) {
            $this->addError('examples', 'Write an example for every word before continuing.');

            return;
        }

        foreach ($filled as $item) {
            if (! $item['word']) {
                continue;
            }

            $alreadyChecked = ($this->feedback[$item['word']]['checkedText'] ?? null) === $item['example'];

            if (! $alreadyChecked) {
                $this->runCheck($item['word'], $item['example']);
            }
        }

        $hasMajorIssue = $filled->contains(
            fn ($item) => $item['word'] && ($this->feedback[$item['word']]['severity'] ?? null) === 'major'
        );

        if ($hasMajorIssue) {
            $this->addError('examples', 'Fix the highlighted sentence before continuing.');

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => $this->stepKey,
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode([
                'selected_words' => collect($words)->pluck('phrase')->values(),
                'examples' => $filled->values(),
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
        return collect($this->words())
            ->map(fn (array $entry) => ['word' => $entry['phrase'], 'meaning' => $entry['meaning']])
            ->values()
            ->all();
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attributes exactly — both build it the same way from the run id
     * and this instance's own step key (day), so the 3 days' drafts never
     * collide with each other.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:{$this->stepKey}:";
    }
};
?>

@php
    $words = $this->words();
    $storySegments = $this->storySegments();
    $spiralCards = $this->readOnly ? [] : $this->spiralReviewCards();
    $initialFilled = collect($examples)->map(fn ($e) => trim((string) $e) !== '')->values();
    $draftPrefix = $this->draftPrefix();
@endphp

@if ($completed)
    <div class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <div>
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-success uppercase dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-4 w-4')
                Vocabulary saved
            </p>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Want to keep practicing these words? Pick which ones join your spaced-repetition notebook.</p>
        </div>

        <div class="space-y-2">
            @foreach ($this->notebookCandidates() as $index => $candidate)
                <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-line p-3 dark:border-line-dark">
                    <input
                        type="checkbox"
                        wire:model="wordsToTrack.{{ $index }}"
                        class="mt-0.5 h-4 w-4 shrink-0 cursor-pointer rounded border-line text-accent focus:ring-accent dark:border-line-dark dark:bg-surface-dark dark:text-accent-dark"
                    >
                    <span>
                        <span class="block text-sm font-bold text-ink dark:text-ink-dark">{{ $candidate['word'] }}</span>
                        <span class="block text-xs text-ink-soft dark:text-ink-soft-dark">{{ $candidate['meaning'] }}</span>
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
<div
    class="space-y-6"
    data-initial-phase="{{ $readOnly || $practiceStarted ? 'practice' : (count($spiralCards) ? 'spiral' : 'story') }}"
    data-initial-filled="{{ $initialFilled->toJson() }}"
    x-data="{
        phase: 'story',
        showStoryAgain: false,
        filled: [],
        dismissed: {},
        init() {
            this.phase = this.$el.dataset.initialPhase;
            this.filled = JSON.parse(this.$el.dataset.initialFilled);
        },
        get filledCount() { return this.filled.filter(Boolean).length },
    }"
>
    <x-hook :text="$run->mission->stepContent($stepKey)['hook'] ?? null" />

    @unless ($readOnly)
        @if (count($spiralCards))
            <div x-show="phase === 'spiral'" x-cloak class="space-y-4">
                <div>
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Quick reminder — yesterday's words</p>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Before today's new words, a fast recap of yesterday's — just a warm-up, skip anytime.</p>
                </div>
                <x-quick-round :cards="$spiralCards" on-complete="phase = 'story'" on-skip="phase = 'story'" />
            </div>
        @endif

        <div x-show="phase === 'story'" x-cloak class="space-y-4">
            <div>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Today's words</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Read the short story below — new words are highlighted, with the full breakdown underneath.</p>
            </div>

            <div class="rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                @include('missions.steps.partials.vocabulary-story', ['segments' => $storySegments, 'words' => $words])
            </div>

            <button
                type="button"
                x-on:click="phase = 'meaning_check'"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >Continue @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
        </div>

        <div x-show="phase === 'meaning_check'" x-cloak class="space-y-4">
            <div>
                <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Quick check before you write</p>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Match each word to its meaning, then its picture — just a warm-up, skip anytime.</p>
            </div>
            <x-quick-round
                :cards="[...$this->meaningCheckCards(), ...$this->imageMatchCards()]"
                on-complete="$wire.call('startPractice'); phase = 'practice'"
                on-skip="$wire.call('startPractice'); phase = 'practice'"
            />
        </div>
    @endunless

    <div x-show="phase === 'practice'" @unless ($readOnly) x-cloak @endunless class="space-y-6">
        <div>
            <button
                type="button"
                x-on:click="showStoryAgain = !showStoryAgain"
                class="inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark"
            >
                <span x-show="!showStoryAgain" class="inline-flex items-center gap-1">@svg('heroicon-o-chevron-right', 'h-3 w-3') Show the story again</span>
                <span x-show="showStoryAgain" x-cloak class="inline-flex items-center gap-1">@svg('heroicon-o-chevron-down', 'h-3 w-3') Hide the story</span>
            </button>
            <div x-show="showStoryAgain" x-cloak class="mt-2 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                @include('missions.steps.partials.vocabulary-story', ['segments' => $storySegments, 'words' => $words])
            </div>
        </div>

        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Write a personal example for each word</p>
            <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">Check one anytime for feedback, or we'll check the rest for you when you move on.</p>
            @unless ($readOnly)
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300"
                            :class="filledCount >= {{ count($words) }} ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark'"
                            :style="`width: ${Math.min(filledCount, {{ count($words) }}) / {{ count($words) }} * 100}%`"
                        ></div>
                        <x-slot:label>
                            <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">
                                <span x-text="filledCount"></span> of {{ count($words) }} written
                            </p>
                        </x-slot:label>
                    </x-progress-bar>
                </div>
            @endunless
        </div>

        <div wire:loading.class="pointer-events-none" wire:target="checkOne,revealCorrection,declineReveal,save" class="space-y-4">
            @foreach ($words as $index => $entry)
                @php $word = $entry['phrase']; $itemFeedback = $feedback[$word] ?? null; @endphp
                <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                    <p class="flex items-baseline gap-2">
                        <span class="text-sm font-bold text-ink dark:text-ink-dark">{{ $word }}</span>
                        @if (! empty($entry['pos']))
                            <span class="rounded-full bg-accent/15 px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-dark/25 dark:text-accent-ink-dark">{{ $entry['pos'] }}</span>
                        @endif
                    </p>
                    <p class="mt-0.5 text-xs text-ink-soft dark:text-ink-soft-dark">What did this one mean again? Try to recall it before you write.</p>

                    <div class="mt-2 flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="examples.{{ $index }}"
                            x-on:input="filled[{{ $index }}] = $el.value.trim() !== ''; dismissed[{{ $index }}] = true"
                            placeholder="My example…"
                            @unless ($readOnly)
                                x-draft="{ key: '{{ $draftPrefix }}examples.{{ $index }}', field: 'examples.{{ $index }}' }"
                            @endunless
                            @readonly($readOnly)
                            wire:loading.attr="disabled"
                            wire:target="checkOne,revealCorrection,declineReveal,save"
                            class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                        >
                        <x-filled-check show="filled[{{ $index }}]" />
                        @unless ($readOnly)
                            <x-check-button method="checkOne" :index="$index" wire-target="checkOne,revealCorrection,declineReveal,save" />
                        @endunless
                    </div>

                    @unless ($readOnly)
                        <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), revealCorrection({{ $index }}), save" class="mt-2" />
                    @endunless

                    <div x-show="!dismissed[{{ $index }}]" x-transition.opacity.duration.300ms>
                        <x-severity-feedback :feedback="$itemFeedback" :error="$checkErrors[$word] ?? null" />
                    </div>

                    @unless ($readOnly)
                        <x-almost-reveal-notice :show="$this->isAlmostRevealing($word)" />
                        <x-reveal-offer
                            :show="$offerReveal[$word] ?? false"
                            :struggling="$this->run->isStruggling()"
                            reveal-method="revealCorrection"
                            decline-method="declineReveal"
                            :index="$index"
                            wire-target="checkOne,revealCorrection,declineReveal,save"
                        />
                    @endunless
                </div>
            @endforeach
        </div>

        @error('examples')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        @unless ($readOnly)
            <x-continue-button
                on-click="filled.forEach((_, i) => dismissed[i] = true); $wire.save().then(() => { dismissed = {} })"
                wire-target="checkOne,revealCorrection,declineReveal,save"
                loading-label="Checking your sentences…"
                ready-when="filledCount >= {{ count($words) }}"
                hint="Write an example for every word to continue"
            />
        @endunless
    </div>
</div>
@endif
