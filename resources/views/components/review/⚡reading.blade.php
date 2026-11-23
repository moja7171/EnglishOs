<?php

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\Mission;
use App\Models\NotebookEntry;
use App\Models\User;
use App\Services\LearnerFeedback;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Review's second path, "Mistakes & reminders": the learner only reads —
 * one mistake or one grammar reminder at a time, with its Persian line. No
 * answer is graded, so nothing is scheduled; reading a card only marks it
 * as read (see User::readingItems()).
 */
new class extends Component
{
    /**
     * How many extra batches the learner asked for with "6 more" — kept in
     * the address, so a reload stays in the same batch.
     */
    #[Url(as: 'more')]
    public int $more = 0;

    /**
     * How many cards were read in THIS page session — the progress counter
     * and the end screen. A reload starts again, and what was already read
     * simply isn't waiting any more.
     */
    public int $sessionRead = 0;

    /**
     * Today's cards still to read, as "type" + "id" entries.
     *
     * @return list<array{type: string, id: int}>
     */
    #[Computed]
    public function queue(): array
    {
        return auth()->user()->dailyReviewItems('talk', $this->more)->all();
    }

    /**
     * What the card on screen shows, or null when nothing is left.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function card(): ?array
    {
        $entry = $this->queue[0] ?? null;

        if ($entry === null) {
            return null;
        }

        $learner = auth()->user();

        return match ($entry['type']) {
            'note' => $this->mistakeCard($learner->notebookEntries()->with('mission:id,code,title')->find($entry['id'])),
            'error' => $this->patternCard($learner->errorPatternReviews()->find($entry['id'])),
            default => $this->grammarCard($learner->grammarPoints()->find($entry['id'])),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mistakeCard(?NotebookEntry $entry): ?array
    {
        if (! $entry) {
            return null;
        }

        return [
            'type' => 'note',
            'id' => $entry->id,
            'kind' => 'mistake',
            'chip' => $entry->heading(),
            'source' => $entry->mission ? $entry->mission->code.' · '.$entry->mission->title : null,
            'wrong' => $entry->original,
            'right' => $entry->better,
            'fa' => $entry->note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function patternCard(?ErrorPatternReview $pattern): ?array
    {
        if (! $pattern) {
            return null;
        }

        return [
            'type' => 'error',
            'id' => $pattern->id,
            'kind' => 'mistake',
            'chip' => LearnerFeedback::MISTAKE_CATEGORIES[$pattern->category] ?? Str::headline($pattern->category),
            'source' => null,
            'wrong' => $pattern->last_error,
            'right' => $pattern->last_correction,
            'fa' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function grammarCard(?GrammarPoint $point): ?array
    {
        if (! $point) {
            return null;
        }

        $mission = Mission::where('code', $point->mission_code)->first();

        return [
            'type' => 'grammar',
            'id' => $point->id,
            'kind' => 'grammar',
            'chip' => 'Grammar',
            'source' => $mission ? $mission->code.' · '.$mission->title : $point->mission_code,
            'focus' => $point->focus,
            'focusFa' => config('grammar_names')[$point->mission_code] ?? null,
            'example' => $point->example_sentence,
            'rules' => array_slice($point->reviewContent()['rules'], 0, 3),
            'reminder' => $point->rule_reminder,
        ];
    }

    /**
     * Reads the card on screen. $type and $id say which card the learner
     * saw, so a double tap can't read the next one unseen.
     */
    public function markRead(string $type, int $id): void
    {
        $card = $this->card;

        if (! $card || $card['type'] !== $type || $card['id'] !== $id) {
            return;
        }

        $learner = auth()->user();

        match ($type) {
            'note' => $learner->notebookEntries()->find($id)?->markRead(),
            'error' => $learner->errorPatternReviews()->find($id)?->markRead(),
            default => $learner->grammarPoints()->find($id)?->markRead(),
        };

        $this->sessionRead++;
        unset($this->queue, $this->card);
    }

    #[Computed]
    public function moreAvailable(): bool
    {
        $learner = auth()->user();

        return $learner->dailyReviewItems('talk', $this->more + 1)->count() > $learner->dailyReviewItems('talk', $this->more)->count();
    }

    public function loadMore(): void
    {
        if ($this->moreAvailable) {
            $this->more++;
            unset($this->queue, $this->card, $this->moreAvailable);
        }
    }
};
?>

<div @class(['mx-auto flex max-w-2xl flex-col gap-4 p-4 sm:p-6', 'h-[calc(100dvh-6rem)]' => $this->card, 'min-h-[calc(100dvh-9rem)]' => ! $this->card])>
    @php
        $card = $this->card;
        $position = $sessionRead + 1;
        $total = $sessionRead + count($this->queue);
    @endphp

    @if (! $card)
        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <span class="animate-trophy-pop inline-flex h-20 w-20 items-center justify-center rounded-full bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                @svg('heroicon-o-check-badge', 'h-10 w-10')
            </span>
            @if ($sessionRead > 0)
                <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">All read</p>
                <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">{{ $sessionRead }} / {{ $sessionRead }}</p>
            @elseif (auth()->user()->reviewedTodayCount('talk') > 0)
                <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">All read today</p>
            @else
                <p class="font-display text-2xl font-semibold text-ink dark:text-ink-dark">Nothing to read yet</p>
                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Your mistakes and grammar show up here.</p>
            @endif
            @if ($this->moreAvailable)
                <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="cursor-pointer rounded-2xl border-2 border-line px-5 py-2.5 text-sm font-extrabold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-plus', 'mr-1 inline h-4 w-4') {{ User::DAILY_TALK_LIMIT }} more</button>
            @endif
        </div>
        <div class="sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] flex flex-col gap-2">
            <a href="{{ route('home') }}" wire:navigate class="w-full rounded-2xl bg-accent py-3.5 text-center text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Done</a>
            <a href="{{ route('notebook.index', ['tab' => 'grammar']) }}" wire:navigate class="w-full rounded-2xl border-2 border-line py-3 text-center text-sm font-extrabold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">Grammar in Notebook</a>
        </div>
    @else
        <x-lesson-header data-step-header :close="route('review.index')" :progress="($position - 1) / max($total, 1)" :counter="$position.'/'.$total" />

        <div wire:key="read-{{ $card['type'] }}-{{ $card['id'] }}" class="animate-review-card-in flex min-h-0 flex-1 flex-col gap-4">
            <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto overscroll-contain rounded-[1.75rem] bg-surface p-5 dark:bg-surface-dark">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span @class([
                        'rounded-full px-3 py-1 text-xs font-extrabold',
                        'bg-danger-soft text-danger-ink' => $card['kind'] === 'mistake',
                        'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark' => $card['kind'] === 'grammar',
                    ])>{{ $card['chip'] }}</span>
                    @if ($card['source'])
                        <span dir="ltr" class="min-w-0 truncate text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $card['source'] }}</span>
                    @endif
                </div>

                @if ($card['kind'] === 'mistake')
                    @if (filled($card['wrong']))
                        <div class="flex flex-col gap-1">
                            <span class="text-xs font-extrabold tracking-wide text-ink-soft uppercase dark:text-ink-soft-dark">You said</span>
                            <p dir="ltr" lang="en" class="text-xl leading-snug text-danger-ink line-through decoration-danger">{{ $card['wrong'] }}</p>
                        </div>
                    @endif
                    @if (filled($card['right']))
                        <div class="flex flex-col gap-1">
                            <span class="text-xs font-extrabold tracking-wide text-ink-soft uppercase dark:text-ink-soft-dark">Better</span>
                            <p dir="ltr" lang="en" class="font-display text-2xl leading-snug font-semibold text-ink dark:text-ink-dark">{{ $card['right'] }}</p>
                        </div>
                    @endif
                    @if (filled($card['fa']))
                        <x-fa-text :text="$card['fa']" class="border-t border-line pt-4 text-base dark:border-line-dark" />
                    @endif
                @else
                    <div class="flex flex-col gap-1">
                        <p dir="ltr" lang="en" class="font-display text-2xl leading-snug font-semibold text-ink dark:text-ink-dark">{{ $card['focus'] }}</p>
                        @if ($card['focusFa'])
                            <x-fa-text :text="$card['focusFa']" class="text-sm text-ink-soft dark:text-ink-soft-dark" />
                        @endif
                    </div>
                    @forelse ($card['rules'] as $rule)
                        <div class="flex flex-col gap-1.5 rounded-2xl bg-accent-soft p-4 dark:bg-accent-soft-dark">
                            {{-- Trusted, developer-authored seed content, light <strong>/<em> allowed. --}}
                            <p dir="ltr" lang="en" class="text-base leading-snug font-semibold text-ink dark:text-ink-dark [&_strong]:text-accent-ink dark:[&_strong]:text-accent-ink-dark">{!! $rule['text'] !!}</p>
                            @if (! empty($rule['fa']))
                                <x-fa-text :text="$rule['fa']" :markup="true" class="border-t border-accent/20 pt-1.5 text-sm text-ink-soft dark:border-accent-dark/30 dark:text-ink-soft-dark" />
                            @endif
                        </div>
                    @empty
                        @if (filled($card['reminder']))
                            <p dir="ltr" lang="en" class="text-base leading-snug text-ink-soft dark:text-ink-soft-dark">{{ $card['reminder'] }}</p>
                        @endif
                    @endforelse
                    @if (filled($card['example']))
                        <p dir="ltr" lang="en" class="rounded-2xl bg-surface-sunken px-4 py-3 text-base font-semibold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">{{ $card['example'] }}</p>
                    @endif
                    <a href="{{ route('notebook.index', ['tab' => 'grammar']) }}" wire:navigate class="mt-auto cursor-pointer self-center text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">Open the full lesson</a>
                @endif
            </div>

            <button type="button" wire:click="markRead('{{ $card['type'] }}', {{ $card['id'] }})" wire:loading.attr="disabled" class="w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-bold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Next</button>
        </div>
    @endif
</div>
