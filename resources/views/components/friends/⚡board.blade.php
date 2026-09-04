<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $pinnedHighlight = '';

    public bool $pinnedHighlightSaved = false;

    /** @var array<int, bool> keyed by friend user id — which cards are expanded to reveal the heatmap/journey map */
    public array $expanded = [];

    public function mount(): void
    {
        $this->pinnedHighlight = (string) (auth()->user()->pinned_highlight ?? '');
    }

    public function toggleExpanded(int $userId): void
    {
        $this->expanded[$userId] = ! ($this->expanded[$userId] ?? false);
    }

    /**
     * A short, entirely self-authored blurb — the one piece of "content"
     * a friend can ever see here, opt-in and never derived from AI-graded
     * Evidence. See the pinned_highlight migration's docblock.
     */
    public function savePinnedHighlight(): void
    {
        $this->pinnedHighlightSaved = false;

        $text = trim($this->pinnedHighlight);

        auth()->user()->update(['pinned_highlight' => $text !== '' ? $text : null]);

        $this->pinnedHighlightSaved = true;
    }

    /**
     * Every number here is a count, percentage, or date — never the
     * content of an actual answer. dayProgress()'s day labels ("Day 1 ·
     * Foundation") and progressPercent() reveal only structure/pace, the
     * same information a mutual friend could already infer from the
     * existing "Following" list's streak/mission badges.
     *
     * @return array{user: User, streak: int, longestStreak: int, missionsCompleted: int, missionsThisWeek: int, calendar: array, progressPercent: ?int, missionTitle: ?string, dayProgress: array, lastActive: ?Carbon}
     */
    private function cardData(User $user): array
    {
        $run = $user->latestInProgressMissionRun();

        return [
            'user' => $user,
            'streak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
            'missionsCompleted' => $user->missionsCompletedCount(),
            'missionsThisWeek' => $user->missionsCompletedThisWeekCount(),
            'calendar' => $user->activityCalendar(),
            'progressPercent' => $run?->progressPercent(),
            'missionTitle' => $run?->mission->title,
            'dayProgress' => $run?->dayProgress() ?? [],
            'lastActive' => $user->activeDates()->first(),
        ];
    }

    #[Computed]
    public function myCard(): array
    {
        return $this->cardData(auth()->user());
    }

    /**
     * @return list<array{user: User, streak: int, longestStreak: int, missionsCompleted: int, missionsThisWeek: int, calendar: array, progressPercent: ?int, missionTitle: ?string, dayProgress: array, lastActive: ?Carbon}>
     */
    #[Computed]
    public function friendCards(): array
    {
        return auth()->user()->mutualFriends()
            ->map(fn (User $friend) => $this->cardData($friend))
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6">
    <a href="{{ route('friends.index') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        Friends
    </a>

    <div class="relative isolate overflow-hidden rounded-3xl bg-linear-to-br from-hero to-hero-2 p-6 text-white">
        <div class="pointer-events-none absolute -top-20 -right-8 -z-10 h-56 w-56 rounded-full bg-dawn opacity-40 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-24 -left-8 -z-10 h-52 w-52 rounded-full bg-dusk opacity-30 blur-3xl"></div>
        <p class="inline-flex items-center gap-1.5 text-xs font-bold tracking-widest text-white/70 uppercase">
            @svg('heroicon-o-user-group', 'h-3.5 w-3.5')
            Friends Board
        </p>
        <h1 class="mt-2 font-display text-2xl font-semibold">See how everyone's doing</h1>
        <p class="mt-1 text-sm text-white/75">Progress and streaks only — nobody's actual answers ever show up here unless they choose to share them.</p>
    </div>

    {{-- My own card — pinned-highlight editor lives here, front and center. --}}
    <div class="rounded-2xl border border-accent/30 bg-surface p-4 shadow-sm dark:border-accent-dark/30 dark:bg-surface-dark">
        <div class="flex items-center gap-3">
            <x-user-avatar :user="auth()->user()" class="h-12 w-12 text-sm" />
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ auth()->user()->name }} <span class="font-normal text-ink-faint dark:text-ink-faint-dark">(you)</span></p>
                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                    @if ($this->myCard['streak'] > 0)
                        <span class="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                            <x-streak-flame :streak="$this->myCard['streak']" size="h-3 w-3" /> {{ $this->myCard['streak'] }}-day streak
                        </span>
                    @endif
                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                        @svg('heroicon-o-check-badge', 'h-3 w-3') {{ $this->myCard['missionsCompleted'] }} {{ Str::plural('mission', $this->myCard['missionsCompleted']) }}
                    </span>
                </div>
            </div>
            @if ($this->myCard['progressPercent'] !== null)
                <x-progress-ring :percent="$this->myCard['progressPercent']" :size="40" />
            @endif
        </div>

        <x-streak-badges :longest-streak="$this->myCard['longestStreak']" class="mt-3" />

        <div class="mt-3 border-t border-line pt-3 dark:border-line-dark">
            <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Pin a highlight — shown on your card to friends</p>
            <form wire:submit="savePinnedHighlight" class="mt-1.5 flex items-center gap-2">
                <input
                    type="text"
                    wire:model="pinnedHighlight"
                    maxlength="80"
                    placeholder="e.g. Learning to talk about my daily routine!"
                    class="w-full rounded-full border border-line bg-transparent px-3 py-1.5 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                >
                <button
                    type="submit"
                    class="shrink-0 cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >Save</button>
            </form>
            @if ($pinnedHighlightSaved)
                <p class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                </p>
            @endif

            @if ($highlight = auth()->user()->pinned_highlight)
                <p class="mt-2 rounded-xl bg-surface-sunken px-3 py-2 text-xs text-ink-soft italic dark:bg-surface-sunken-dark dark:text-ink-soft-dark">Shown to friends: "{{ $highlight }}"</p>
            @endif
        </div>
    </div>

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Friends ({{ count($this->friendCards) }})</p>

        @if (count($this->friendCards) === 0)
            <div class="mt-2 flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-8 text-center dark:border-line-dark">
                {{-- Two not-yet-connected busts (same vetted heroicon-s-user
                     silhouette <x-illustrated-avatar> reuses) with a dashed
                     line and a "+" between them — plain primitives only,
                     matching this app's no-freehand-bezier illustration
                     style, no external asset. --}}
                <svg viewBox="0 0 160 90" class="h-16 w-32" aria-hidden="true">
                    <circle cx="34" cy="45" r="30" class="fill-accent-soft dark:fill-accent-soft-dark" />
                    <circle cx="126" cy="45" r="30" class="fill-surface-sunken dark:fill-surface-sunken-dark" />
                    <g transform="translate(20, 31) scale(1.15)" class="fill-accent-ink dark:fill-accent-ink-dark">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" />
                    </g>
                    <g transform="translate(112, 31) scale(1.15)" class="fill-ink-faint dark:fill-ink-faint-dark">
                        <path fill-rule="evenodd" clip-rule="evenodd" d="M7.5 6a4.5 4.5 0 1 1 9 0 4.5 4.5 0 0 1-9 0ZM3.751 20.105a8.25 8.25 0 0 1 16.498 0 .75.75 0 0 1-.437.695A18.683 18.683 0 0 1 12 22.5c-2.786 0-5.433-.608-7.812-1.7a.75.75 0 0 1-.437-.695Z" />
                    </g>
                    <line x1="64" y1="45" x2="96" y2="45" stroke-width="2.5" stroke-dasharray="1 7" stroke-linecap="round" class="stroke-line dark:stroke-line-dark" />
                    <circle cx="80" cy="45" r="10" class="fill-accent dark:fill-accent-dark" />
                    <line x1="80" y1="40" x2="80" y2="50" stroke="white" stroke-width="2" stroke-linecap="round" />
                    <line x1="75" y1="45" x2="85" y2="45" stroke="white" stroke-width="2" stroke-linecap="round" />
                </svg>
                <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Once you and a friend follow each other back, they'll show up here.</p>
                <a href="{{ route('friends.index') }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink dark:text-accent-ink-dark">
                    Find friends @svg('heroicon-o-arrow-right', 'h-3 w-3')
                </a>
            </div>
        @endif

        <div class="mt-2 space-y-3">
            @foreach ($this->friendCards as $card)
                @php $isExpanded = $expanded[$card['user']->id] ?? false; @endphp
                <div class="rounded-2xl border border-line bg-surface p-3.5 shadow-sm transition-shadow hover:shadow-md dark:border-line-dark dark:bg-surface-dark">
                    <div class="flex items-center gap-3">
                        <x-user-avatar :user="$card['user']" class="h-11 w-11 text-sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $card['user']->name }}</p>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                @if ($card['streak'] > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-accent-soft px-2 py-0.5 text-[11px] font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                        <x-streak-flame :streak="$card['streak']" size="h-3 w-3" /> {{ $card['streak'] }}-day streak
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                                    @svg('heroicon-o-check-badge', 'h-3 w-3') {{ $card['missionsCompleted'] }} {{ Str::plural('mission', $card['missionsCompleted']) }}
                                </span>
                                @if ($card['missionsThisWeek'] > 0)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-surface-sunken px-2 py-0.5 text-[11px] font-medium text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark">
                                        {{ $card['missionsThisWeek'] }} this week
                                    </span>
                                @endif
                            </div>
                        </div>
                        @if ($card['progressPercent'] !== null)
                            <x-progress-ring :percent="$card['progressPercent']" :size="40" />
                        @endif
                    </div>

                    @if ($card['user']->pinned_highlight)
                        <p class="mt-2.5 rounded-xl bg-surface-sunken px-3 py-2 text-xs text-ink-soft italic dark:bg-surface-sunken-dark dark:text-ink-soft-dark">"{{ $card['user']->pinned_highlight }}"</p>
                    @endif

                    <x-streak-badges :longest-streak="$card['longestStreak']" class="mt-2.5" />

                    <button
                        type="button"
                        wire:click="toggleExpanded({{ $card['user']->id }})"
                        class="mt-2.5 inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark"
                    >
                        @svg($isExpanded ? 'heroicon-o-chevron-up' : 'heroicon-o-chevron-down', 'h-3.5 w-3.5')
                        {{ $isExpanded ? 'Hide details' : 'Show activity & progress' }}
                    </button>

                    @if ($isExpanded)
                        <div class="mt-3 space-y-3 border-t border-line pt-3 dark:border-line-dark">
                            <x-activity-heatmap :calendar="$card['calendar']" />

                            @if (count($card['dayProgress']))
                                <div>
                                    <p class="text-xs font-semibold text-ink dark:text-ink-dark">Currently on: {{ $card['missionTitle'] }}</p>
                                    <div class="mt-2">
                                        <x-mission-journey-map :day-progress="$card['dayProgress']" />
                                    </div>
                                </div>
                            @else
                                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not currently in a mission.</p>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
