<?php

use App\Models\ErrorLogItem;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** '' means "no goal" — wire:model on a <select> needs a string, not null. */
    public string $weeklyGoalDays = '';

    public bool $weeklyGoalSaved = false;

    public function mount(): void
    {
        $user = auth()->user();

        $this->weeklyGoalDays = $user->weekly_goal_days ? (string) $user->weekly_goal_days : '';
    }

    /**
     * Everything here is already computed elsewhere in the app
     * (streak/missions on Friends' list, top recurring error on Active
     * Recall and Mission Result) but never shown to the learner
     * themselves in one place — this page is purely a mirror onto data
     * that already exists, no new tracking added. Moved here from
     * Profile's old "My progress" tab, unchanged — Settings isn't
     * somewhere a learner checks daily.
     *
     * @return array{currentStreak: int, longestStreak: int, missionsCompleted: int, vocabularyCount: int, topError: ?ErrorLogItem, calendar: list<array{date: string, label: string, active: bool, future: bool}>, activeDaysThisWeek: int}
     */
    #[Computed]
    public function progressStats(): array
    {
        $user = auth()->user();

        return [
            'currentStreak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
            'missionsCompleted' => $user->missionsCompletedCount(),
            'vocabularyCount' => $user->vocabularyWordsSelected()->count(),
            'topError' => $user->topRecurringError(),
            'calendar' => $user->activityCalendar(),
            'activeDaysThisWeek' => $user->activeDaysThisWeek(),
        ];
    }

    /**
     * @return list<array{type: string, label: string, freshness: int}>
     */
    #[Computed]
    public function freshnessItems(): array
    {
        return auth()->user()->memoryFreshnessItems();
    }

    #[Computed]
    public function averageFreshness(): ?int
    {
        return auth()->user()->averageMemoryFreshness();
    }

    /**
     * A plain manual check, not $this->validate() — the <select> only ever
     * offers "no goal" (empty string) or 1-7, and Laravel's "nullable"
     * rule only skips other rules for a genuine null, not an empty
     * string, so "nullable|in:1..7" would reject the very value the UI's
     * own empty option submits.
     */
    public function updateWeeklyGoal(): void
    {
        $this->weeklyGoalSaved = false;

        if ($this->weeklyGoalDays !== '' && ! in_array($this->weeklyGoalDays, ['1', '2', '3', '4', '5', '6', '7'], true)) {
            return;
        }

        auth()->user()->update(['weekly_goal_days' => $this->weeklyGoalDays !== '' ? (int) $this->weeklyGoalDays : null]);

        unset($this->progressStats);
        $this->weeklyGoalSaved = true;
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-chart-bar', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">My Progress</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Everything you've built so far, in one place.</p>
        </div>
    </header>

    <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                @svg('heroicon-s-fire', 'h-3.5 w-3.5') Current streak
            </p>
            <p class="mt-1 text-2xl font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $this->progressStats['currentStreak'] }}</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                @svg('heroicon-o-trophy', 'h-3.5 w-3.5') Longest streak
            </p>
            <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['longestStreak'] }}</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                @svg('heroicon-o-check-badge', 'h-3.5 w-3.5') Missions completed
            </p>
            <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['missionsCompleted'] }}</p>
        </div>
        <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
            <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                @svg('heroicon-o-book-open', 'h-3.5 w-3.5') Words learned
            </p>
            <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['vocabularyCount'] }}</p>
        </div>
    </div>

    <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
        <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
            @svg('heroicon-o-bolt', 'h-3.5 w-3.5') Memory freshness
        </p>

        @if ($this->averageFreshness === null)
            <p class="mt-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">Once you've reviewed a word, speaking prompt, or grammar pattern at least once, its memory freshness shows up here.</p>
        @else
            @php
                $avg = $this->averageFreshness;
                $barColor = $avg >= 66 ? 'bg-success dark:bg-success-dark' : ($avg >= 33 ? 'bg-amber-500' : 'bg-red-600');
                $textColor = $avg >= 66 ? 'text-success dark:text-success-dark' : ($avg >= 33 ? 'text-amber-600' : 'text-red-600');
            @endphp
            <div class="mt-2 flex items-center gap-3">
                <div class="flex-1">
                    <x-progress-bar>
                        <div class="h-full rounded-full transition-all duration-300 {{ $barColor }}" style="width: {{ $avg }}%"></div>
                    </x-progress-bar>
                </div>
                <span class="shrink-0 text-sm font-bold {{ $textColor }}">{{ $avg }}%</span>
            </div>
            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Average across everything you're tracking — it fades the longer something goes unreviewed.</p>

            @if (count($this->freshnessItems))
                <div class="mt-3 space-y-1.5 border-t border-line pt-2.5 dark:border-line-dark">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Fading fastest</p>
                    @foreach (array_slice($this->freshnessItems, 0, 3) as $item)
                        @php
                            $itemColor = $item['freshness'] >= 66 ? 'text-success dark:text-success-dark' : ($item['freshness'] >= 33 ? 'text-amber-600' : 'text-red-600');
                        @endphp
                        <div class="flex items-center justify-between gap-2 text-sm text-ink dark:text-ink-dark">
                            <span class="truncate">{{ $item['label'] }} <span class="text-xs text-ink-faint dark:text-ink-faint-dark">({{ $item['type'] }})</span></span>
                            <span class="shrink-0 text-xs font-semibold {{ $itemColor }}">{{ $item['freshness'] }}%</span>
                        </div>
                    @endforeach
                    <a href="{{ route('review.index') }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark">
                        Review now
                        @svg('heroicon-o-arrow-right', 'h-3 w-3')
                    </a>
                </div>
            @endif
        @endif
    </div>

    <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Weekly goal</p>
                @if (auth()->user()->weekly_goal_days)
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $this->progressStats['activeDaysThisWeek'] }} of {{ auth()->user()->weekly_goal_days }} days this week</p>
                @else
                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Set a goal to track your week here.</p>
                @endif
            </div>
            <form wire:submit="updateWeeklyGoal" class="flex items-center gap-2">
                <select
                    wire:model="weeklyGoalDays"
                    class="rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                >
                    <option value="">No goal</option>
                    @foreach (range(1, 7) as $n)
                        <option value="{{ $n }}">{{ $n }} {{ Str::plural('day', $n) }}/week</option>
                    @endforeach
                </select>
                <button
                    type="submit"
                    class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Save</button>
            </form>
        </div>

        @if (auth()->user()->weekly_goal_days)
            <div class="mt-2">
                <x-progress-bar>
                    <div
                        class="h-full rounded-full transition-all duration-300 {{ $this->progressStats['activeDaysThisWeek'] >= auth()->user()->weekly_goal_days ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark' }}"
                        style="width: {{ min($this->progressStats['activeDaysThisWeek'] / auth()->user()->weekly_goal_days, 1) * 100 }}%"
                    ></div>
                </x-progress-bar>
            </div>
        @endif

        @if ($weeklyGoalSaved)
            <p class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
            </p>
        @endif
    </div>

    <div class="rounded-xl border border-line bg-surface p-3 dark:border-line-dark dark:bg-surface-dark">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Activity</p>
        <x-activity-heatmap :calendar="$this->progressStats['calendar']" />
    </div>

    @if ($topError = $this->progressStats['topError'])
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
            <p class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 uppercase dark:text-amber-400">
                @svg('heroicon-o-arrow-path', 'h-3.5 w-3.5') Your most recurring mistake
            </p>
            <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                <span class="text-red-600 line-through decoration-red-500">{{ $topError->error }}</span>
                <span class="text-success dark:text-success-dark">{{ $topError->correction }}</span>
            </p>
            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">This has come up across more than one mission — Active Recall keeps bringing it back for extra practice.</p>
        </div>
    @else
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Complete 2+ missions and any pattern in your mistakes will show up here.</p>
    @endif
</div>
