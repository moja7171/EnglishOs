<?php

use App\Models\VocabularyWord;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
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

    /**
     * The card flips and animates in the browser (see <x-vocabulary-review-card>),
     * so this is the one round-trip per word. Again/Good/Easy map onto
     * SM-2's 0-5 quality scale (1/4/5) the way Anki's simplified grading
     * does. A learner who wasn't sure ("show me") can only ever fail the
     * word — enforced here, not just by hiding the other buttons.
     */
    public function gradeWord(int $quality, bool $remembered): void
    {
        $this->currentWord?->review($remembered ? $quality : 1);

        unset($this->dueWords, $this->currentWord);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
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
            <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Pick some words in a mission's New Words step and they'll show up here.</p>
        </div>
    @elseif (! $this->currentWord)
        <div class="flex flex-col items-center gap-2 card p-8 text-center">
            @svg('heroicon-o-check-badge', 'h-6 w-6 text-success dark:text-success-dark')
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">You're all caught up!</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Nothing due for review right now — come back later.</p>
        </div>
    @else
        @php $word = $this->currentWord; @endphp
        <div wire:key="review-{{ $word->id }}">
            <x-vocabulary-review-card
                :word="$word"
                :remaining="$this->dueWords->count() - 1"
                :caption="$this->dueWords->count().' due'"
            />
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
                        <x-speak-word :word="$item->word" class="text-sm font-semibold text-ink dark:text-ink-dark" />
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
