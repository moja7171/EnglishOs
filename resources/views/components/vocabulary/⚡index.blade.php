<?php

use App\Models\VocabularyWord;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $sentence = '';

    /** True once the learner has flipped a self-assessment card to see the meaning. */
    public bool $revealed = false;

    /** @var array{severity: string, hint: string}|null */
    public ?array $feedback = null;

    public ?string $checkError = null;

    /**
     * True once the learner has tapped through (or skipped) the meaning
     * diagnostic <x-quick-round> for the current word — see
     * diagnosticCard(). Reset on advance() so the next word gets its own
     * fresh round.
     */
    public bool $diagnosticDone = false;

    #[Computed]
    public function dueWords()
    {
        return auth()->user()->vocabularyWords()
            ->where('next_review_at', '<=', now())
            ->orderBy('next_review_at')
            ->get();
    }

    #[Computed]
    public function currentWord(): ?VocabularyWord
    {
        return $this->dueWords->first();
    }

    #[Computed]
    public function allWords()
    {
        return auth()->user()->vocabularyWords()->orderBy('word')->get();
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    /**
     * A one-card meaning-match <x-quick-round> shown before the deeper
     * written review for a brand-new (or just-reset) word — a quick,
     * ungraded warm-up, not another gate. Distractor meanings come from
     * the learner's OTHER words, so with fewer than 3 words total there's
     * nothing plausible to build them from and the diagnostic is skipped
     * entirely (written review shows immediately, as before).
     *
     * @return array{prompt: string, options: list<string>, correct: int}|null
     */
    public function diagnosticCard(): ?array
    {
        $word = $this->currentWord;

        if (! $word) {
            return null;
        }

        $distractors = auth()->user()->vocabularyWords()
            ->where('id', '!=', $word->id)
            ->inRandomOrder()
            ->limit(2)
            ->pluck('meaning')
            ->filter();

        if ($distractors->count() < 2) {
            return null;
        }

        $options = collect([$word->meaning, ...$distractors])->shuffle()->values();

        return ['prompt' => $word->word, 'options' => $options->all(), 'correct' => $options->search($word->meaning)];
    }

    /**
     * The quick path — only ever available once a word has already
     * passed at least one written, AI-checked review (see
     * VocabularyWord::needsWrittenReview()). Again/Good/Easy map onto
     * SM-2's 0-5 quality scale the same way Anki's simplified grading
     * does: a real fail, a normal pass, and a confident pass.
     */
    public function gradeSelf(int $quality): void
    {
        $word = $this->currentWord;

        if (! $word || $word->needsWrittenReview()) {
            return;
        }

        $word->review($quality);
        $this->advance();
    }

    /**
     * The deeper path for a brand-new word (or one just knocked back to
     * the start by a failed review) — reuses the same SentenceChecker
     * every mission step's sentence input already trusts, so the
     * standard is identical to what "using this word correctly" already
     * means everywhere else in the app. Deliberately NOT gated behind
     * the missions' 3-attempts-then-reveal pattern (see
     * TracksCheckAttempts): there is no "must pass to continue" here —
     * every check is a valid review outcome (a "major" verdict just
     * means the word goes back to day 1), so the learner reads the
     * feedback and moves on with Next, rather than being forced to
     * retry until they pass.
     */
    public function checkSentence(): void
    {
        $word = $this->currentWord;
        $text = trim($this->sentence);

        if (! $word) {
            return;
        }

        if ($text === '') {
            $this->checkError = 'Write a sentence first.';

            return;
        }

        $this->checkError = null;

        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner used the target word correctly, naturally, and as a '
                    .'genuine sentence (not just repeating the dictionary definition).',
                majorCriteria: 'the word is missing or used with the wrong meaning, the sentence just repeats '
                    .'the definition',
                context: "a sentence using the word \"{$word->word}\"",
                text: $text,
            );

            $this->feedback = $data;

            $word->review(match ($data['severity']) {
                'major' => 1,
                'minor' => 4,
                default => 5,
            });
        } catch (ConnectionException|RequestException) {
            $this->checkError = "Couldn't reach the AI service — please try again.";
        } catch (Throwable $e) {
            $this->checkError = "Couldn't check this one: {$e->getMessage()}";
        }
    }

    public function nextWord(): void
    {
        $this->advance();
    }

    private function advance(): void
    {
        $this->sentence = '';
        $this->revealed = false;
        $this->feedback = null;
        $this->checkError = null;
        $this->diagnosticDone = false;
        unset($this->dueWords, $this->currentWord);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-book-open', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">My words</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Every word you've picked, reviewed on its own schedule.</p>
        </div>
    </header>

    @if ($this->allWords->isEmpty())
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-10 text-center dark:border-line-dark">
            @svg('heroicon-o-book-open', 'h-6 w-6 text-ink-faint/60 dark:text-ink-faint-dark/60')
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Pick some words in a mission's Vocabulary Builder step and they'll show up here.</p>
        </div>
    @elseif (! $this->currentWord)
        <div class="flex flex-col items-center gap-2 rounded-2xl border border-line bg-surface p-8 text-center dark:border-line-dark dark:bg-surface-dark">
            @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due for review right now — come back later.</p>
        </div>
    @else
        @php $word = $this->currentWord; @endphp
        <div wire:key="review-{{ $word->id }}" class="space-y-4 rounded-2xl border border-line bg-surface p-5 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                {{ $this->dueWords->count() }} {{ Str::plural('word', $this->dueWords->count()) }} due for review
            </p>

            <p class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $word->word }}</p>

            {{--
                Checked first, ahead of needsWrittenReview(): checkSentence()
                already calls $word->review() the moment it gets a verdict,
                which immediately changes repetitions — so branching on the
                word's live state here would yank the feedback UI away
                before the learner ever sees it or clicks Next. Whether
                feedback is showing is tracked by $this->feedback instead,
                independent of what happened to the word underneath it.
            --}}
            @if ($this->feedback)
                <x-severity-feedback :feedback="$this->feedback" />

                <button
                    type="button"
                    wire:click="nextWord"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                >Next word @svg('heroicon-o-arrow-right', 'h-3.5 w-3.5')</button>
            @elseif ($word->needsWrittenReview() && ! $diagnosticDone && ($diagnosticCard = $this->diagnosticCard()))
                {{-- A quick meaning-match warm-up before the deeper written
                     review below — ungraded, always skippable. --}}
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Quick check before you write — pick the right meaning.</p>
                <x-quick-round
                    :cards="[$diagnosticCard]"
                    on-complete="$wire.set('diagnosticDone', true)"
                    on-skip="$wire.set('diagnosticDone', true)"
                />
            @elseif ($word->needsWrittenReview())
                {{-- Written, AI-checked review — the deeper path for a
                     brand-new or just-reset word. --}}
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $word->meaning }}</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Write a sentence using this word.</p>
                <div class="flex items-center gap-2">
                    <input
                        type="text"
                        wire:model="sentence"
                        wire:keydown.enter="checkSentence"
                        placeholder="My example…"
                        wire:loading.attr="disabled"
                        wire:target="checkSentence"
                        class="w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink disabled:opacity-50 dark:border-line-dark dark:text-ink-dark"
                    >
                    <button
                        type="button"
                        wire:click="checkSentence"
                        wire:loading.attr="disabled"
                        wire:target="checkSentence"
                        class="shrink-0 cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >Check</button>
                </div>
                <x-ai-thinking wire:loading wire:target="checkSentence" />
                @if ($checkError)
                    <p class="text-xs text-red-600">{{ $checkError }}</p>
                @endif
            @else
                {{-- Quick self-assessment — a word already reviewed
                     successfully at least once. --}}
                @if (! $revealed)
                    <button
                        type="button"
                        wire:click="reveal"
                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-eye', 'h-4 w-4') Show meaning</button>
                @else
                    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">{{ $word->meaning }}</p>
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Did you remember it?</p>
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="gradeSelf(1)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-red-300 hover:bg-red-50 hover:text-red-600 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-red-950"
                        >Again</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(4)"
                            class="cursor-pointer rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >Good</button>
                        <button
                            type="button"
                            wire:click="gradeSelf(5)"
                            class="cursor-pointer rounded-full border border-success/40 px-4 py-2 text-sm font-semibold text-success transition-colors hover:bg-success-soft dark:border-success-dark/40 dark:text-success-dark dark:hover:bg-success-soft-dark"
                        >Easy</button>
                    </div>
                @endif
            @endif
        </div>
    @endif

    @if ($this->allWords->isNotEmpty())
        <div x-data="{ showAll: false }">
            <button
                type="button"
                x-on:click="showAll = !showAll"
                class="flex w-full cursor-pointer items-center justify-between gap-2 text-xs font-semibold tracking-wide text-ink-faint uppercase transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
            >
                <span>All my words ({{ $this->allWords->count() }})</span>
                <span class="transition-transform" :class="showAll ? 'rotate-180' : ''">
                    @svg('heroicon-o-chevron-down', 'h-3.5 w-3.5')
                </span>
            </button>

            <div x-show="showAll" x-cloak x-transition.opacity.duration.150ms class="mt-2 space-y-1.5">
                @foreach ($this->allWords as $item)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-line px-3.5 py-2 dark:border-line-dark">
                        <span class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $item->word }}</span>
                        <span class="text-xs text-ink-faint dark:text-ink-faint-dark">
                            @if ($item->isDue())
                                Due now
                            @else
                                Next review {{ $item->next_review_at->diffForHumans() }}
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
