<?php

use App\Models\ErrorLogItem;
use App\Models\ErrorPatternReview;
use App\Models\Mission;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** '' means "no goal" — wire:model on a <select> needs a string, not null. */
    public string $weeklyGoalDays = '';

    public bool $weeklyGoalSaved = false;

    /** The month currently shown in <x-month-calendar> — starts on today's month. */
    public int $calendarYear;

    public int $calendarMonth;

    public function mount(): void
    {
        $user = auth()->user();

        $this->weeklyGoalDays = $user->weekly_goal_days ? (string) $user->weekly_goal_days : '';
        $this->calendarYear = (int) now()->year;
        $this->calendarMonth = (int) now()->month;
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->subMonthNoOverflow();
        $this->calendarYear = $date->year;
        $this->calendarMonth = $date->month;
    }

    /** Never lets the learner navigate past the current real month. */
    public function nextMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->addMonthNoOverflow();

        if ($date->gt(now()->startOfMonth())) {
            return;
        }

        $this->calendarYear = $date->year;
        $this->calendarMonth = $date->month;
    }

    #[Computed]
    public function isCurrentCalendarMonth(): bool
    {
        return $this->calendarYear === (int) now()->year && $this->calendarMonth === (int) now()->month;
    }

    #[Computed]
    public function calendarMonthLabel(): string
    {
        return Carbon::create($this->calendarYear, $this->calendarMonth, 1)->format('F Y');
    }

    #[Computed]
    public function calendarDays(): array
    {
        return auth()->user()->activityForMonth($this->calendarYear, $this->calendarMonth);
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
     * @return array{currentStreak: int, longestStreak: int, vocabularyCount: int, topError: ?ErrorLogItem, calendar: list<array{date: string, label: string, active: bool, future: bool}>, activeDaysThisWeek: int}
     */
    #[Computed]
    public function progressStats(): array
    {
        $user = auth()->user();

        return [
            'currentStreak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
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

    /** @return array<string, float> */
    #[Computed]
    public function skillAverages(): array
    {
        return auth()->user()->skillAverages();
    }

    /** @return array{category: string, totalCount: int, recentCount: int}|null */
    #[Computed]
    public function topErrorTrend(): ?array
    {
        return auth()->user()->topRecurringErrorTrend();
    }

    /**
     * The positive half of the error data — see <x-mistakes-you-fixed>.
     * Kept as two separate computed properties rather than one array so
     * each stays a plain pass-through to the model method that owns it.
     *
     * @return Collection<int, ErrorPatternReview>
     */
    #[Computed]
    public function masteredErrors(): Collection
    {
        return auth()->user()->masteredErrorPatterns();
    }

    /** @return Collection<int, ErrorPatternReview> */
    #[Computed]
    public function fadingErrors(): Collection
    {
        return auth()->user()->fadingErrorPatterns();
    }

    /**
     * The Grammar in Context equivalent of masteredErrors/fadingErrors —
     * see User::masteredGrammarPoints()/learningGrammarPoints(). Kept as
     * two separate computed properties for the same reason those two are.
     *
     * @return Collection<int, GrammarPoint>
     */
    #[Computed]
    public function masteredGrammarPoints(): Collection
    {
        return auth()->user()->masteredGrammarPoints();
    }

    /** @return Collection<int, GrammarPoint> */
    #[Computed]
    public function learningGrammarPoints(): Collection
    {
        return auth()->user()->learningGrammarPoints();
    }

    #[Computed]
    public function totalPracticeMinutes(): int
    {
        return auth()->user()->totalPracticeMinutes();
    }

    /** @return list<array{label: string, count: int}> */
    #[Computed]
    public function vocabularyGrowth(): array
    {
        return auth()->user()->vocabularyGrowthByWeek();
    }

    /** One tap on a goal option in the picker: '' clears the goal, '1'-'7' sets it. */
    public function setWeeklyGoal(string $days): void
    {
        $this->weeklyGoalDays = $days;

        $this->updateWeeklyGoal();
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


<div
    class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6"
    x-data="{
        tab: ['overview', 'activity', 'growth'].includes(location.hash.slice(1)) ? location.hash.slice(1) : 'overview',
        setTab(name) {
            this.tab = name;
            history.replaceState(history.state, '', '#' + name);
        },
    }"
>
    <a href="{{ route('home') }}" wire:navigate class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-chart-bar', 'h-5 w-5')
        </span>
        <div class="min-w-0">
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">My Progress</h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">
                <span class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-book-open', 'h-3.5 w-3.5 text-ink-faint dark:text-ink-faint-dark') {{ $this->progressStats['vocabularyCount'] }} {{ Str::plural('word', $this->progressStats['vocabularyCount']) }}
                </span>
                <span class="inline-flex items-center gap-1.5">
                    @svg('heroicon-o-clock', 'h-3.5 w-3.5 text-ink-faint dark:text-ink-faint-dark') {{ Mission::formatDuration($this->totalPracticeMinutes) }}
                </span>
            </p>
        </div>
    </header>

    <div role="tablist" aria-label="Progress sections" class="grid grid-cols-3 gap-1 card p-1">
        @foreach (['overview' => 'Overview', 'activity' => 'Activity', 'growth' => 'Growth'] as $tabKey => $tabLabel)
            <button
                type="button"
                role="tab"
                x-on:click="setTab('{{ $tabKey }}')"
                :aria-selected="tab === '{{ $tabKey }}'"
                :class="tab === '{{ $tabKey }}' ? 'bg-accent text-white dark:bg-accent-dark' : 'text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark'"
                class="cursor-pointer rounded-full px-3 py-2 text-sm font-semibold transition-colors"
            >
                {{ $tabLabel }}
                @if ($tabKey === 'growth' && ($this->masteredErrors->isNotEmpty() || $this->fadingErrors->isNotEmpty()))
                    {{-- A bare dot, never a count: see <x-mistakes-you-fixed>. --}}
                    <span class="ml-0.5 inline-block h-1.5 w-1.5 rounded-full bg-success align-middle dark:bg-success-dark" title="New wins to see"></span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- Overview — only what's useful today: the streak, the week's goal,
         what to review, and the reassurance surface. --}}
    <div x-show="tab === 'overview'" role="tabpanel" class="space-y-4">
        <div class="card p-4">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                        <x-streak-flame :streak="$this->progressStats['currentStreak']" animated /> Current streak
                    </p>
                    <p class="mt-1 text-3xl font-extrabold text-accent-ink dark:text-accent-ink-dark">
                        <span x-data x-count-up="{{ $this->progressStats['currentStreak'] }}">{{ $this->progressStats['currentStreak'] }}</span>
                        <span class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ Str::plural('day', $this->progressStats['currentStreak']) }}</span>
                    </p>
                </div>
                <p class="inline-flex items-center gap-1 pb-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                    @svg('heroicon-o-trophy', 'h-3.5 w-3.5') Best: {{ $this->progressStats['longestStreak'] }}
                </p>
            </div>
            <x-milestone-path :current-streak="$this->progressStats['currentStreak']" class="mt-2" />
        </div>

        @php
            $weeklyGoal = auth()->user()->weekly_goal_days ? (int) auth()->user()->weekly_goal_days : null;
            $thisWeek = array_slice($this->progressStats['calendar'], -7);
            $weekdayInitials = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
        @endphp
        <div class="card p-4" x-data="{ pickerOpen: false }" x-on:keydown.escape="pickerOpen = false">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Weekly goal</p>
                    @if ($weeklyGoal)
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $this->progressStats['activeDaysThisWeek'] }} of {{ $weeklyGoal }} days this week</p>
                    @else
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Set a goal to track your week here.</p>
                    @endif
                </div>

                <div class="relative">
                    <button
                        type="button"
                        x-on:click="pickerOpen = ! pickerOpen"
                        :aria-expanded="pickerOpen"
                        class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >
                        {{ $weeklyGoal ? $weeklyGoal.' '.Str::plural('day', $weeklyGoal).'/week' : 'No goal' }}
                        @svg('heroicon-o-chevron-down', 'h-3 w-3')
                    </button>

                    <div
                        x-show="pickerOpen"
                        x-cloak
                        x-on:click.outside="pickerOpen = false"
                        class="absolute right-0 z-10 mt-2 w-56 card p-2 shadow-lg"
                    >
                        <div class="grid grid-cols-7 gap-1">
                            @foreach (range(1, 7) as $n)
                                <button
                                    type="button"
                                    wire:click="setWeeklyGoal('{{ $n }}')"
                                    x-on:click="pickerOpen = false"
                                    wire:loading.attr="disabled"
                                    wire:target="setWeeklyGoal"
                                    @class([
                                        'cursor-pointer rounded-lg py-1.5 text-sm font-semibold transition-colors disabled:opacity-50',
                                        'bg-accent text-white dark:bg-accent-dark' => $weeklyGoal === $n,
                                        'text-ink hover:bg-surface-sunken dark:text-ink-dark dark:hover:bg-surface-sunken-dark' => $weeklyGoal !== $n,
                                    ])
                                >{{ $n }}</button>
                            @endforeach
                        </div>
                        <button
                            type="button"
                            wire:click="setWeeklyGoal('')"
                            x-on:click="pickerOpen = false"
                            wire:loading.attr="disabled"
                            wire:target="setWeeklyGoal"
                            class="mt-1 w-full cursor-pointer rounded-lg py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:opacity-50 dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >No goal</button>
                    </div>
                </div>
            </div>

            <div class="mt-3 grid grid-cols-7 gap-1.5">
                @foreach ($thisWeek as $index => $day)
                    <div class="flex flex-col items-center gap-1" title="{{ $day['label'] }}{{ $day['active'] ? ' — practiced' : '' }}">
                        <span class="text-[10px] text-ink-faint dark:text-ink-faint-dark">{{ $weekdayInitials[$index] }}</span>
                        <span
                            @class([
                                'inline-flex h-7 w-7 items-center justify-center rounded-full',
                                'bg-accent text-white dark:bg-accent-dark' => $day['active'],
                                'bg-surface-sunken dark:bg-surface-sunken-dark' => ! $day['active'] && ! $day['future'],
                                'border border-dashed border-line dark:border-line-dark' => $day['future'],
                            ])
                        >
                            @if ($day['active'])
                                @svg('heroicon-s-check', 'h-3.5 w-3.5')
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>

            @if ($weeklyGoalSaved)
                <p class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                </p>
            @endif
        </div>

        <div class="card p-4">
            <p class="inline-flex items-center gap-1 text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">
                @svg('heroicon-o-bolt', 'h-3.5 w-3.5') Memory freshness
            </p>

            @if ($this->averageFreshness === null)
                <p class="mt-1.5 text-xs text-ink-faint dark:text-ink-faint-dark">Once you've reviewed a word, speaking prompt, or grammar pattern at least once, its memory freshness shows up here.</p>
            @else
                @php
                    $avg = $this->averageFreshness;
                    $barColor = $avg >= 66 ? 'bg-success dark:bg-success-dark' : ($avg >= 33 ? 'bg-warning' : 'bg-red-600');
                    $textColor = $avg >= 66 ? 'text-success dark:text-success-dark' : ($avg >= 33 ? 'text-warning-ink' : 'text-danger-ink');
                @endphp
                <div class="mt-2 flex items-center gap-3">
                    <div class="flex-1">
                        <x-progress-bar>
                            <div class="h-full rounded-full transition-all duration-300 {{ $barColor }}" style="width: {{ $avg }}%"></div>
                        </x-progress-bar>
                    </div>
                    <span class="shrink-0 text-sm font-bold {{ $textColor }}">{{ $avg }}%</span>
                </div>

                @if (count($this->freshnessItems))
                    <div class="mt-2 space-y-1.5">
                        @foreach (array_slice($this->freshnessItems, 0, 3) as $item)
                            @php
                                $itemColor = $item['freshness'] >= 66 ? 'text-success dark:text-success-dark' : ($item['freshness'] >= 33 ? 'text-warning-ink' : 'text-danger-ink');
                            @endphp
                            <div class="flex items-center justify-between gap-2 text-sm text-ink dark:text-ink-dark">
                                <span class="truncate">{{ $item['label'] }} <span class="text-xs text-ink-faint dark:text-ink-faint-dark">({{ $item['type'] }})</span></span>
                                <span class="shrink-0 text-xs font-semibold {{ $itemColor }}">{{ $item['freshness'] }}%</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <a href="{{ route('review.index') }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark">
                    Review now
                    @svg('heroicon-o-arrow-right', 'h-3 w-3')
                </a>
            @endif
        </div>
    </div>

    {{-- Activity — looking back: the 12-week strip and a real month. --}}
    <div x-show="tab === 'activity'" x-cloak role="tabpanel" class="card space-y-4 p-4">
        <x-activity-heatmap :calendar="$this->progressStats['calendar']" />

        <div class="border-t border-line pt-4 dark:border-line-dark">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $this->calendarMonthLabel }}</p>
                <div class="flex items-center gap-1">
                    <button
                        type="button"
                        wire:click="previousMonth"
                        wire:loading.attr="disabled"
                        wire:target="previousMonth"
                        title="Previous month"
                        class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink disabled:pointer-events-none disabled:opacity-30 dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                    >@svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')</button>
                    <button
                        type="button"
                        wire:click="nextMonth"
                        wire:loading.attr="disabled"
                        wire:target="nextMonth"
                        title="Next month"
                        @disabled($this->isCurrentCalendarMonth)
                        class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink disabled:pointer-events-none disabled:opacity-30 dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark"
                    >@svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
                </div>
            </div>
            <div class="mt-2">
                <x-month-calendar :days="$this->calendarDays" />
            </div>
        </div>
    </div>

    {{-- Growth — everything that answers "what got better": the
         reassurance surface first, then skills, vocabulary and grammar. --}}
    <div x-show="tab === 'growth'" x-cloak role="tabpanel" class="card space-y-4 p-4">
        <x-mistakes-you-fixed :mastered="$this->masteredErrors" :fading="$this->fadingErrors" :boxed="false" />

        @if (count($this->skillAverages))
            <div class="border-t border-line pt-4 dark:border-line-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Skills</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Your average self-assessment across every completed mission.</p>
                <x-skill-radar :skills="$this->skillAverages" class="mt-2" />
            </div>
        @endif

        @if (collect($this->vocabularyGrowth)->sum('count') > 0)
            <div class="border-t border-line pt-4 dark:border-line-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Vocabulary growth</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">New words added per week.</p>
                <x-bar-chart :data="$this->vocabularyGrowth" class="mt-2" />
            </div>
        @endif

        {{--
            The Grammar in Context equivalent of <x-mistakes-you-fixed> —
            but a plain stat here, not a reassurance surface, since a
            taught rule was never a mistake to begin with. See
            User::masteredGrammarPoints()/learningGrammarPoints().
        --}}
        @if ($this->masteredGrammarPoints->isNotEmpty() || $this->learningGrammarPoints->isNotEmpty())
            <div class="border-t border-line pt-4 dark:border-line-dark">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Grammar points</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Rules taught across your missions, each on its own review schedule.</p>

                @if ($this->masteredGrammarPoints->isNotEmpty())
                    <ul class="mt-2.5 space-y-1.5">
                        @foreach ($this->masteredGrammarPoints as $point)
                            <li class="inline-flex w-full items-center gap-1.5 text-sm text-ink dark:text-ink-dark">
                                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5 shrink-0 text-success dark:text-success-dark')
                                <span class="truncate">{{ $point->focus }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->learningGrammarPoints->isNotEmpty())
                    <ul class="mt-2.5 space-y-1.5">
                        @foreach ($this->learningGrammarPoints as $point)
                            <li class="flex items-center justify-between gap-3 text-sm text-ink dark:text-ink-dark">
                                <span class="truncate">{{ $point->focus }}</span>
                                <span class="shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark">
                                    @if ($point->isDue())
                                        Due now
                                    @else
                                        Next review {{ $point->next_review_at->diffForHumans() }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <div class="border-t border-line pt-4 dark:border-line-dark">
            @if ($topError = $this->progressStats['topError'])
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-warning-ink uppercase">
                    @svg('heroicon-o-arrow-path', 'h-3.5 w-3.5') Your most recurring mistake
                </p>
                @if ($trend = $this->topErrorTrend)
                    <p class="mt-1 flex items-center gap-1 text-sm font-semibold {{ $trend['recentCount'] === 0 ? 'text-success dark:text-success-dark' : 'text-warning-ink' }}">
                        @svg($trend['recentCount'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-trending-down', 'h-3.5 w-3.5')
                        @if ($trend['recentCount'] === 0)
                            Hasn't come up in your last 2 missions — looking good!
                        @else
                            {{ $trend['recentCount'] }} of {{ $trend['totalCount'] }} times total came from your last 2 missions.
                        @endif
                    </p>
                @endif
                <a href="{{ route('review.index') }}" wire:navigate class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink transition-colors hover:opacity-80 dark:text-accent-ink-dark">
                    Review it in Active Recall
                    @svg('heroicon-o-arrow-right', 'h-3 w-3')
                </a>
            @else
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Complete 2+ missions and any pattern in your mistakes will show up here.</p>
            @endif
        </div>
    </div>
</div>
