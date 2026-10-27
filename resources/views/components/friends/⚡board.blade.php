<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $pinnedHighlight = '';

    public bool $pinnedHighlightSaved = false;

    public function mount(): void
    {
        $this->pinnedHighlight = (string) (auth()->user()->pinned_highlight ?? '');
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
        unset($this->myCard, $this->ranked);
    }

    /**
     * Every number here is a count, percentage, or date — never the
     * content of an actual answer. dayProgress()'s day labels ("Day 1 ·
     * Foundation") and progressPercent() reveal only structure/pace, the
     * same information a mutual friend could already infer from the
     * existing "Following" list's streak/mission badges.
     *
     * @return array{user: User, streak: int, longestStreak: int, missionsCompleted: int, missionsThisWeek: int, calendar: array, progressPercent: ?int, overallPercent: int, missionCode: ?string, missionTitle: ?string, dayProgress: array, lastActive: ?Carbon}
     */
    private function cardData(User $user): array
    {
        $run = $user->currentInProgressMissionRun();

        return [
            'user' => $user,
            'streak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
            'missionsCompleted' => $user->missionsCompletedCount(),
            'missionsThisWeek' => $user->missionsCompletedThisWeekCount(),
            'calendar' => $user->activityCalendar(),
            'progressPercent' => $run?->progressPercent(),
            'overallPercent' => (int) round(($user->missionsCompletedCount() * 100 + ($run?->progressPercent() ?? 0)) / 24),
            'missionCode' => $run?->mission->code,
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

    /**
     * Everyone on one league table, me included: longest current streak
     * first, then overall progress.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function ranked(): array
    {
        return collect($this->friendCards)
            ->push($this->myCard)
            ->sortBy([['streak', 'desc'], ['overallPercent', 'desc']])
            ->values()
            ->all();
    }
};
?>

@php
    $rankColors = ['text-[#d4a017]', 'text-[#8f8cb0]', 'text-[#b06a3b]'];
@endphp

{{-- One league table by streak, me in it; a tap opens that person's
     sheet (their activity, mission days, badges, Message) — or, on my own
     row, the highlight I pin for friends. Counts and dates only, never an
     answer. --}}
<div class="mx-auto max-w-2xl px-4 pt-3 pb-6 sm:px-6">
    <header class="flex items-center gap-2">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="-ml-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <h1 class="flex-1 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Board</h1>
    </header>

    @if (count($this->friendCards) === 0)
        <div class="mt-6 flex flex-col items-center gap-2 rounded-2xl border border-dashed border-line py-8 text-center dark:border-line-dark">
            {{-- Two not-yet-connected busts with a dashed line and a "+"
                 between them — plain primitives only. --}}
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

    <ol class="mt-3 -mx-2 space-y-1">
        @foreach ($this->ranked as $card)
            @php
                $isMe = $card['user']->id === auth()->id();
                $highlight = $card['user']->pinned_highlight;
            @endphp
            <li wire:key="rank-{{ $card['user']->id }}" x-data="{ open: false }">
                <button
                    type="button"
                    x-on:click="open = true"
                    @class([
                        'flex w-full cursor-pointer items-center gap-3 rounded-2xl px-2 py-2.5 text-left',
                        'bg-accent-soft dark:bg-accent-soft-dark' => $isMe,
                        'hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark' => ! $isMe,
                    ])
                >
                    <span class="w-6 shrink-0 text-center text-sm font-extrabold {{ $rankColors[$loop->index] ?? 'text-ink-faint dark:text-ink-faint-dark' }}">{{ $loop->iteration }}</span>
                    <x-user-avatar :user="$card['user']" class="h-10 w-10 text-xs" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-extrabold text-ink dark:text-ink-dark">{{ $isMe ? 'You' : $card['user']->name }}</span>
                        <span class="block truncate text-xs text-ink-faint italic dark:text-ink-faint-dark">{{ $highlight ? '"'.$highlight.'"' : ($isMe ? 'Tap to pin a highlight' : '') }}</span>
                    </span>
                    <span @class(['shrink-0 text-sm font-extrabold', 'text-accent-ink dark:text-accent-ink-dark' => $card['streak'] > 0, 'text-ink-faint dark:text-ink-faint-dark' => $card['streak'] === 0])>{{ $card['streak'] > 0 ? '🔥 '.$card['streak'] : '—' }}</span>
                    <x-progress-ring :percent="$card['overallPercent']" :size="38" />
                </button>

                @if ($isMe)
                    <x-bottom-sheet show="open" label="Your highlight">
                        <x-slot:title>Your highlight</x-slot:title>
                        <form wire:submit="savePinnedHighlight" x-on:submit="open = false" class="space-y-3">
                            <input type="text" wire:model="pinnedHighlight" maxlength="80" placeholder="e.g. Practicing every morning before work ☕" aria-label="Your highlight" class="w-full rounded-2xl border-2 border-accent bg-transparent px-3 py-2.5 text-sm text-ink dark:border-accent-dark dark:text-ink-dark">
                            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Friends see this on the board.</p>
                            <button type="submit" class="w-full cursor-pointer rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white dark:bg-accent-dark">Save</button>
                        </form>
                    </x-bottom-sheet>
                @else
                    <x-bottom-sheet show="open" :label="$card['user']->name">
                        <div class="flex items-center gap-3">
                            <x-user-avatar :user="$card['user']" class="h-12 w-12 text-sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-base font-extrabold text-ink dark:text-ink-dark">{{ $card['user']->name }}</p>
                                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                                    @if ($card['missionCode'])
                                        On {{ $card['missionCode'] }} · {{ $card['missionTitle'] }}
                                    @else
                                        Not currently in a mission.
                                    @endif
                                </p>
                            </div>
                            @if ($card['streak'] > 0)
                                <span class="text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">🔥 {{ $card['streak'] }}</span>
                            @endif
                        </div>
                        @if (count($card['dayProgress']))
                            <div class="flex gap-1.5" aria-label="Mission days">
                                @foreach ($card['dayProgress'] as $missionDay)
                                    <span title="{{ $missionDay['label'] }}" @class([
                                        'h-2 flex-1 rounded-full',
                                        'bg-success dark:bg-success-dark' => $missionDay['done'],
                                        'bg-linear-to-r from-success from-50% to-line to-50% dark:from-success-dark dark:to-line-dark' => $missionDay['current'],
                                        'bg-line dark:bg-line-dark' => ! $missionDay['done'] && ! $missionDay['current'],
                                    ])></span>
                                @endforeach
                            </div>
                        @endif
                        <x-activity-heatmap :calendar="$card['calendar']" />
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-surface-sunken px-2.5 py-1 text-xs font-extrabold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">✓ {{ $card['missionsCompleted'] }} {{ Str::plural('mission', $card['missionsCompleted']) }}</span>
                            <x-streak-badges :longest-streak="$card['longestStreak']" />
                        </div>
                        <a href="{{ route('friends.conversation', $card['user']) }}" wire:navigate class="block w-full rounded-2xl bg-accent px-5 py-3.5 text-center text-base font-extrabold text-white dark:bg-accent-dark">Message</a>
                    </x-bottom-sheet>
                @endif
            </li>
        @endforeach
    </ol>
</div>
