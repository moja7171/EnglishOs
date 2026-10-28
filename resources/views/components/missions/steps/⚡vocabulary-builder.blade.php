<?php

use App\Livewire\Concerns\DictatesAnswers;
use App\Livewire\Concerns\KeepsStepDraft;
use App\Livewire\Concerns\PreviewsStep;
use App\Livewire\Concerns\TracksAiUsage;
use App\Livewire\Concerns\TracksCheckAttempts;
use App\Livewire\Concerns\TracksVocabularyNotebook;
use App\Models\Evidence;
use App\Models\MissionRun;
use App\Models\VocabularyWord;
use App\Services\AiFailure;
use App\Services\PexelsClient;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * New Words, one task per screen (lesson design 2): a card per word (picture,
 * sound, Persian meaning, one example), the day's short story, one quick
 * check per word, then one personal sentence per word — typed or said —
 * checked one at a time. Saving adds every word to My Words by itself.
 *
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
    use DictatesAnswers;
    use KeepsStepDraft;
    use PreviewsStep;
    use TracksAiUsage;
    use TracksCheckAttempts;
    use TracksVocabularyNotebook;
    use WithFileUploads;

    public MissionRun $run;

    public bool $readOnly = false;

    /** e.g. 'vocabulary_builder_2' — which day of the 3 this instance is. */
    public string $stepKey = 'vocabulary_builder_1';

    /**
     * True once the learner has moved into practice at least once — after
     * this, the story phase stays reachable via a "show again" link, but
     * the phase machine defaults straight back to practice on
     * re-render so a Check click doesn't bounce them back to page 1.
     */
    public bool $practiceStarted = false;

    /** @var array<int, string> one example per word, same order as words() */
    public array $examples = [];

    /** @var array<string, array{severity: string, hint: string}> keyed by word */
    public array $feedback = [];

    /** @var array<string, string> keyed by word — per-input check failure message */
    public array $checkErrors = [];

    public function mount(): void
    {
        $this->examples = array_fill(0, count($this->words()), '');

        if (! $this->readOnly) {
            $this->restoreStepDraft();

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
    public function wordImageUrl(string $phrase): ?string
    {
        $word = collect($this->words())->firstWhere('phrase', $phrase);
        $query = $word['image_query'] ?? null;

        if (! $query) {
            return null;
        }

        return app(PexelsClient::class)->imageUrlFor($phrase, $query, null);
    }

    /**
     * The quick check between the story and the sentences — ONE card per
     * word, mixed: a word with a picture (every other one) asks for its
     * picture, the rest for its meaning, in Persian when every option has
     * one. Distractor meanings come from the mission's OTHER days' words
     * first: one taken from today's own words is the right answer to
     * another card, so the round could be finished by elimination alone.
     *
     * @return list<array{prompt: string, options: list<string>, correct: int, label: string, optionType?: string, difficulty?: string}>
     */
    public function quizCards(): array
    {
        $pictureCards = collect($this->imageMatchCards())->keyBy('prompt');

        return collect($this->meaningCheckCards())
            ->map(fn (array $card, int $index) => $index % 2 === 1 && $pictureCards->has($card['prompt'])
                ? [...$pictureCards[$card['prompt']], 'label' => 'Pick the picture']
                : [...$card, 'label' => 'Pick the meaning'])
            ->values()
            ->all();
    }

    /**
     * @return list<array{prompt: string, options: list<string>, correct: int, difficulty?: string}>
     */
    public function meaningCheckCards(): array
    {
        $words = collect($this->words());
        $todaysPhrases = $words->pluck('phrase');
        $inPersian = $words->every(fn (array $word) => filled($word['meaning_fa'] ?? null));
        $meaningOf = fn (array $word) => $inPersian ? ($word['meaning_fa'] ?? null) : ($word['meaning'] ?? null);

        $fromOtherDays = collect($this->run->mission->vocabularyEntries())
            ->reject(fn (array $other) => $todaysPhrases->contains($other['phrase']))
            ->map($meaningOf)
            ->filter()
            ->unique()
            ->values();

        return $words
            ->map(function (array $entry) use ($words, $fromOtherDays, $meaningOf) {
                $meaning = $meaningOf($entry);
                $distractors = $fromOtherDays
                    ->reject(fn (string $other) => $other === $meaning)
                    ->shuffle()
                    ->take(2);

                if ($distractors->count() < 2) {
                    $distractors = $distractors->concat(
                        $words
                            ->where('phrase', '!=', $entry['phrase'])
                            ->map($meaningOf)
                            ->filter()
                            ->shuffle()
                            ->take(2 - $distractors->count())
                    );
                }

                $options = collect([$meaning, ...$distractors])->shuffle()->values();

                return [
                    'prompt' => $entry['phrase'],
                    'options' => $options->all(),
                    'correct' => $options->search($meaning),
                    ...(isset($entry['difficulty']) ? ['difficulty' => $entry['difficulty']] : []),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * "Which picture means this word?" for a word with an image_query —
     * quizCards() swaps some meaning cards for these.
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

    protected function draftPhase(): string
    {
        return $this->stepKey;
    }

    protected function draftedProperties(): array
    {
        return ['practiceStarted'];
    }

    /**
     * The mic in a sentence box: what was said becomes that box's text and
     * is checked straight away, like a typed sentence.
     */
    public function dictateExample(int $index): void
    {
        $word = $this->words()[$index]['phrase'] ?? null;
        $text = $word ? $this->dictatedText($index) : null;

        if ($text === null) {
            return;
        }

        $this->examples[$index] = $text;
        $this->runCheck($word, $text);
    }

    /** Kept in the step's draft, so a learner who leaves comes back to practice, not the story. */
    public function startPractice(): void
    {
        $this->practiceStarted = true;

        $this->keepStepDraft();
    }

    public function checkOne(int $index): bool
    {
        $word = $this->words()[$index]['phrase'] ?? null;

        if (! $word) {
            return false;
        }

        $example = trim($this->examples[$index] ?? '');

        if ($example === '') {
            $this->checkErrors[$word] = 'Write something first.';

            return false;
        }

        return $this->runCheck($word, $example);
    }

    /**
     * Asks the shared SentenceChecker to judge one word/sentence pair. See
     * EOS-009 §8 "الگوی چک جمله" for the shared rules.
     */
    private function runCheck(string $word, string $example): bool
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

            return true;
        } catch (ConnectionException|RequestException) {
            // RequestException's message carries the raw HTTP response body
            // (which can be an arbitrarily large error page, not a clean
            // API message) — never show that to the learner.
            $this->checkErrors[$word] = "Couldn't reach the AI service — please try again.";

            return false;
        } catch (Throwable $e) {
            $this->checkErrors[$word] = AiFailure::message($e, "Couldn't check this one");

            return false;
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

        // Today's words join My Words by themselves (lesson design 2): the
        // old pick-and-confirm screen is gone.
        $this->initWordsToTrack();
        $this->addWordsToNotebook();

        $this->forgetStepDraft();
        $this->dispatch('clear-draft', prefix: $this->draftPrefix());

        $this->redirect($this->run->urlAfterSavingAStep(), navigate: true);
    }

    /** How many of today's words aren't in My Words yet — the "+N" on the last sentence. */
    public function newWordCount(): int
    {
        $existing = VocabularyWord::where('learner_id', $this->run->learner_id)
            ->whereIn('word', collect($this->words())->pluck('phrase'))
            ->pluck('word')
            ->map(fn (string $word) => mb_strtolower($word));

        return collect($this->words())->reject(fn (array $word) => $existing->contains(mb_strtolower($word['phrase'])))->count();
    }

    /**
     * @return list<array{word: string, meaning: string, pos: ?string, example: ?string, user_sentence: ?string}>
     */
    protected function notebookCandidates(): array
    {
        return collect($this->words())
            ->map(fn (array $entry, int $index) => [
                'word' => $entry['phrase'],
                'meaning' => $entry['meaning'],
                'meaning_fa' => $entry['meaning_fa'] ?? null,
                'pos' => $entry['pos'] ?? null,
                'example' => $entry['example'] ?? null,
                'user_sentence' => trim((string) ($this->examples[$index] ?? '')) ?: null,
            ])
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
    $wordCount = count($words);
    $storySegments = $this->storySegments();
    $initialFilled = collect($examples)->map(fn ($e) => trim((string) $e) !== '')->values();
    $draftPrefix = $this->draftPrefix();
    $firstUnwritten = $initialFilled->search(false);
    // Screens: 0 start · 1..n one card per word · n+1 story · n+2 quick
    // check · n+3.. one sentence per word.
    $storyScreen = $wordCount + 1;
    $quizScreen = $wordCount + 2;
    $firstSentenceScreen = $wordCount + 3;
    $screenCount = $wordCount * 2 + 3;
    $startScreen = $practiceStarted ? $firstSentenceScreen + ($firstUnwritten === false ? $wordCount - 1 : $firstUnwritten) : 0;
@endphp

@if ($readOnly)
    <div class="flex flex-col gap-3 pt-2">
        @foreach ($words as $index => $entry)
            <div class="flex flex-col gap-0.5 rounded-2xl bg-surface px-4 py-3 dark:bg-surface-dark">
                <x-speak-word :word="$entry['phrase']" class="lesson-word text-ink dark:text-ink-dark" />
                @if (filled($entry['meaning_fa'] ?? null))
                    <p dir="rtl" class="font-fa lesson-meaning text-ink-soft dark:text-ink-soft-dark">{{ $entry['meaning_fa'] }}</p>
                @else
                    <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $entry['meaning'] }}</p>
                @endif
                @if (filled($examples[$index] ?? null))
                    <p class="text-sm text-ink dark:text-ink-dark">“{{ $examples[$index] }}”</p>
                @endif
            </div>
        @endforeach
    </div>
@else
<div
    x-data="{
        screen: {{ $startScreen }},
        quizShare: 0,
        edited: {},
        english: {},
        get share() {
            return this.screen === {{ $quizScreen }}
                ? ({{ $quizScreen }} + this.quizShare) / {{ $screenCount }}
                : (this.screen + 0.5) / {{ $screenCount }};
        },
    }"
    x-effect="$dispatch('step-progress', { share })"
    x-on:quick-round-progress="quizShare = $event.detail.share"
    x-back-close.step="{ open: screen > 0, close: () => screen-- }"
>
    <x-lesson-screen when="screen === 0">
        <x-step-intro kind="New Words" :minutes="$run->mission->stepDuration($stepKey)" :title="$run->mission->stepContent($stepKey)['hook'] ?? null" />
        <div class="flex flex-wrap gap-2">
            @foreach ($words as $entry)
                <span class="rounded-full bg-surface px-3 py-1.5 text-sm font-bold text-ink-soft dark:bg-surface-dark dark:text-ink-soft-dark">{{ $entry['phrase'] }}</span>
            @endforeach
        </div>

        <x-slot:actions>
            <x-continue-button on-click="screen = 1" wire-target="save">Start</x-continue-button>
        </x-slot:actions>
    </x-lesson-screen>

    {{-- One card per word; its sound plays as the card opens. --}}
    @foreach ($words as $index => $entry)
        @php $picture = $this->wordImageUrl($entry['phrase']); @endphp
        <x-lesson-screen when="screen === {{ $index + 1 }}" x-effect="if (screen === {{ $index + 1 }}) window.eosVoice?.speak({{ Illuminate\Support\Js::from($entry['phrase']) }})">
            <div class="flex flex-col gap-3 rounded-3xl bg-surface p-4 dark:bg-surface-dark">
                @if ($picture)
                    <img src="{{ $picture }}" alt="" loading="lazy" class="h-36 w-full rounded-2xl object-cover">
                @endif
                @if (filled($entry['pos'] ?? null))
                    <span class="w-fit rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $entry['pos'] }}</span>
                @endif
                <x-speak-word :word="$entry['phrase']" class="text-2xl font-bold text-ink dark:text-ink-dark" />
                @if (filled($entry['meaning_fa'] ?? null))
                    <p dir="rtl" class="font-fa text-base font-medium text-ink dark:text-ink-dark">{{ $entry['meaning_fa'] }}</p>
                    <button type="button" x-on:click="english[{{ $index }}] = ! english[{{ $index }}]" class="w-fit cursor-pointer text-xs font-bold text-ink-faint dark:text-ink-faint-dark">In English</button>
                    <p x-show="english[{{ $index }}]" x-cloak class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $entry['meaning'] }}</p>
                @else
                    <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $entry['meaning'] }}</p>
                @endif
                @if (filled($entry['example'] ?? null))
                    <p class="text-sm leading-relaxed text-ink-soft dark:text-ink-soft-dark">{!! preg_replace('/'.preg_quote(e($entry['phrase']), '/').'/i', '<b class="rounded bg-accent-soft px-1 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">$0</b>', e($entry['example']), 1) !!}</p>
                @endif
            </div>
            <div class="flex justify-center gap-1.5" aria-hidden="true">
                @foreach ($words as $dot)
                    <span @class(['h-2 rounded-full', 'w-5 bg-accent dark:bg-accent-dark' => $loop->index === $index, 'w-2 bg-line dark:bg-line-dark' => $loop->index !== $index])></span>
                @endforeach
            </div>

            <x-slot:actions>
                <x-continue-button on-click="screen++" wire-target="save">{{ $index + 1 < $wordCount ? 'Next word' : 'Continue' }}</x-continue-button>
            </x-slot:actions>
        </x-lesson-screen>
    @endforeach

    <x-lesson-screen when="screen === {{ $storyScreen }}">
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">Read it</span>
        <p x-lookup class="rounded-3xl bg-surface p-4 text-base leading-loose text-ink dark:bg-surface-dark dark:text-ink-dark">
            @foreach ($storySegments as $segment)
                @if ($segment['type'] === 'text')
                    {{ $segment['value'] }}
                @else
                    <mark class="rounded bg-accent-soft px-1 font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $segment['value'] }}</mark>
                @endif
            @endforeach
        </p>
        <p class="text-center text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Tap a word to see it again</p>

        <x-slot:actions>
            <x-continue-button on-click="screen++" wire-target="save" />
        </x-slot:actions>
    </x-lesson-screen>

    <section x-show="screen === {{ $quizScreen }}" x-cloak class="animate-lesson-screen-in pt-2">
        <x-quick-round
            lesson
            :cards="$this->quizCards()"
            label="Pick the meaning"
            draft-key="{{ $draftPrefix }}quiz"
            on-complete="if (screen === {{ $quizScreen }}) { $wire.call('startPractice'); screen = {{ $firstSentenceScreen }} }"
            on-skip="$wire.call('startPractice'); screen = {{ $firstSentenceScreen }}"
        />
    </section>

    {{-- One sentence per word, typed or said; the check comes up from the bottom. --}}
    @foreach ($words as $index => $entry)
        @php
            $word = $entry['phrase'];
            $itemFeedback = $feedback[$word] ?? null;
            $isLast = $index === $wordCount - 1;
        @endphp
        <x-lesson-screen when="screen === {{ $firstSentenceScreen + $index }}" wire:key="sentence-{{ $index }}">
            <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">About you · {{ $index + 1 }} of {{ $wordCount }}</span>
            <x-speak-word :word="$word" class="text-xl font-bold text-ink dark:text-ink-dark" />

            <x-type-or-say
                model="examples.{{ $index }}"
                method="dictateExample"
                :param="$index"
                draft-key="{{ $draftPrefix }}examples.{{ $index }}"
                placeholder="Write a sentence about you…"
                on-input="edited[{{ $index }}] = true"
                :error="$dictationErrors[$index] ?? null"
            />
            <x-ai-thinking wire:loading wire:target="checkOne({{ $index }}), dictateExample, revealCorrection({{ $index }}), save" />

            <x-slot:actions>
                <x-checked-answer-actions
                    :index="$index"
                    :model="'examples.'.$index"
                    :feedback="$itemFeedback"
                    :error="$checkErrors[$word] ?? null"
                    check="$wire.checkOne({{ $index }})"
                    :next="$isLast ? '$wire.save()' : 'screen++'"
                    wire-target="checkOne,dictateExample,revealCorrection,save"
                    :offer-reveal="$offerReveal[$word] ?? false"
                    :almost-revealing="$this->isAlmostRevealing($word)"
                >
                    @if ($isLast && $this->newWordCount() > 0)
                        <x-slot:extra>
                            <span class="inline-flex rounded-full bg-ink px-3 py-1.5 text-xs font-bold text-ground dark:bg-ink-dark dark:text-ground-dark">+{{ $this->newWordCount() }} words in My Words</span>
                        </x-slot:extra>
                    @endif
                </x-checked-answer-actions>
            </x-slot:actions>
        </x-lesson-screen>
    @endforeach

    @error('examples')
        <p class="text-sm text-danger-ink">{{ $message }}</p>
    @enderror
</div>
@endif
