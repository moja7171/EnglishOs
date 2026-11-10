<?php

use App\Models\User;
use App\Services\FriendActivity;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** "Last week" tab instead of this week. */
    public bool $lastWeek = false;

    /** What the table is ranked by: practice days or points in the competitive games. */
    public string $by = 'days';

    public string $pinnedHighlight = '';

    public bool $pinnedHighlightSaved = false;

    public function mount(): void
    {
        $this->pinnedHighlight = (string) (auth()->user()->pinned_highlight ?? '');
    }

    /**
     * A short, entirely self-authored blurb — the one piece of "content"
     * a friend can ever see, opt-in and never derived from AI-graded
     * Evidence. See the pinned_highlight migration's docblock.
     */
    public function savePinnedHighlight(): void
    {
        $this->pinnedHighlightSaved = false;

        $text = trim($this->pinnedHighlight);

        auth()->user()->update(['pinned_highlight' => $text !== '' ? $text : null]);

        $this->pinnedHighlightSaved = true;
    }

    public function showWeek(bool $lastWeek): void
    {
        $this->lastWeek = $lastWeek;
        unset($this->ranked);
    }

    public function rankBy(string $by): void
    {
        $this->by = $by === 'games' ? 'games' : 'days';
        unset($this->ranked);
    }

    /**
     * Everyone on one table, me included: most practice days this week
     * (Saturday to Friday) first, then the most done, then the longer
     * streak — or, on the Games tab, the most points in the competitive
     * games first. Counts and dates only, never an answer.
     *
     * @return list<array{user: User, me: bool, days: int, amount: int, games: int, week: list<array<string, mixed>>, streak: int, mission: ?string}>
     */
    #[Computed]
    public function ranked(): array
    {
        $me = auth()->user();
        $activity = app(FriendActivity::class);
        $start = $activity->weekStart($me, $this->lastWeek ? 1 : 0);
        $today = $me->localToday();

        return $me->mutualFriends()
            ->push($me)
            ->map(function (User $user) use ($me, $activity, $start, $today) {
                $run = $user->currentInProgressMissionRun();
                $currentDay = $run ? collect($run->dayProgress())->search(fn (array $day) => ! $day['done']) : false;

                return [
                    'user' => $user,
                    'me' => $user->is($me),
                    ...$activity->weekRow($user, $start, $today),
                    'games' => $activity->gamePoints($user, $start),
                    'streak' => $user->currentStreak(),
                    'mission' => $run ? $run->mission->code.($currentDay !== false ? ' · D'.($currentDay + 1) : '') : null,
                ];
            })
            ->sortBy($this->by === 'games' ? [['games', 'desc'], ['days', 'desc'], ['streak', 'desc']] : [['days', 'desc'], ['amount', 'desc'], ['streak', 'desc']])
            ->values()
            ->all();
    }
};
?>

@php
    $me = auth()->user();
    $activity = app(App\Services\FriendActivity::class);
    $start = $activity->weekStart($me, $lastWeek ? 1 : 0);
    $daysToNewWeek = (int) $me->localToday()->diffInDays($activity->weekStart($me)->addWeek());
    $rankColors = ['text-[#d4a017]', 'text-[#8f8cb0]', 'text-[#b06a3b]'];
    $hasFriends = count($this->ranked) > 1;
@endphp

{{-- One table of this week's practice days (Saturday to Friday), me in it;
     a friend's row opens their page, mine the highlight I pin for friends. --}}
<div class="mx-auto max-w-2xl px-4 pt-3 pb-6 sm:px-6">
    <header class="flex items-center gap-2">
        <a href="{{ route('friends.index') }}" wire:navigate aria-label="Back to Friends" class="-ml-2 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <h1 class="flex-1 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Board</h1>
    </header>

    @if (! $hasFriends)
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

    <div class="mt-3 grid grid-cols-2 gap-1 rounded-2xl bg-surface-sunken p-1 dark:bg-surface-sunken-dark" role="tablist">
        @foreach ([false => 'This week', true => 'Last week'] as $isLast => $tab)
            <button type="button" role="tab" aria-selected="{{ $lastWeek === (bool) $isLast ? 'true' : 'false' }}" wire:key="tab-{{ (int) $isLast }}" wire:click="showWeek({{ $isLast ? 'true' : 'false' }})" @class([
                'h-10 cursor-pointer rounded-xl text-[15px]',
                'bg-surface font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => $lastWeek === (bool) $isLast,
                'font-bold text-ink-soft dark:text-ink-soft-dark' => $lastWeek !== (bool) $isLast,
            ])>{{ $tab }}</button>
        @endforeach
    </div>

    <div class="mt-2 grid grid-cols-2 gap-1 rounded-2xl bg-surface-sunken p-1 dark:bg-surface-sunken-dark" role="tablist" aria-label="Rank by">
        @foreach (['days' => 'Practice days', 'games' => 'Game points'] as $key => $tab)
            <button type="button" role="tab" aria-selected="{{ $by === $key ? 'true' : 'false' }}" wire:key="by-{{ $key }}" wire:click="rankBy('{{ $key }}')" @class([
                'h-10 cursor-pointer rounded-xl text-[15px]',
                'bg-surface font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => $by === $key,
                'font-bold text-ink-soft dark:text-ink-soft-dark' => $by !== $key,
            ])>{{ $tab }}</button>
        @endforeach
    </div>

    <div class="mt-3 flex items-baseline justify-between text-sm font-bold text-ink-soft dark:text-ink-soft-dark">
        <span>{{ $start->format('D j M') }} – {{ $start->copy()->addDays(6)->format('D j M') }}</span>
        @unless ($lastWeek)
            <span class="text-xs">New week in {{ $daysToNewWeek }} {{ Str::plural('day', $daysToNewWeek) }}</span>
        @endunless
    </div>

    {{-- My row stays above the tabs until the list reaches it. A sticky
         element traps z-index, so the sheet has to live outside the list. --}}
    <div x-data="{ open: false }">
    <ol class="mt-2 space-y-1.5">
        @foreach ($this->ranked as $row)
            <li wire:key="rank-{{ $row['user']->id }}" @class(['sticky bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-10' => $row['me']])>
                @php
                    $rowClass = 'flex w-full cursor-pointer items-center gap-2.5 rounded-2xl px-3 py-2.5 text-left '.($row['me'] ? 'bg-accent-soft shadow-md dark:bg-accent-soft-dark' : 'bg-surface shadow-sm dark:bg-surface-dark');
                @endphp
                @if ($row['me'])
                    <button type="button" x-on:click="open = true" class="{{ $rowClass }}">
                @else
                    <a href="{{ route('friends.profile', $row['user']) }}" wire:navigate class="{{ $rowClass }}">
                @endif
                    <span class="w-5 shrink-0 text-center text-base font-extrabold {{ $rankColors[$loop->index] ?? 'text-ink-faint dark:text-ink-faint-dark' }}">{{ $loop->iteration }}</span>
                    <x-user-avatar :user="$row['user']" class="h-10 w-10 text-xs" />
                    <span class="min-w-0 flex-1 space-y-1.5">
                        <span class="flex items-baseline gap-1.5">
                            <span class="truncate text-[15px] font-extrabold text-ink dark:text-ink-dark">{{ $row['me'] ? 'You' : $row['user']->name }}</span>
                            @if ($row['mission'])
                                <span class="shrink-0 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $row['mission'] }}</span>
                            @endif
                        </span>
                        <span class="flex gap-1" aria-label="{{ $row['days'] }} {{ Str::plural('day', $row['days']) }} practised">
                            @foreach ($row['week'] as $day)
                                <span wire:key="dot-{{ $row['user']->id }}-{{ $day['date'] }}" class="flex w-5 flex-col items-center gap-0.5" aria-hidden="true">
                                    <span @class([
                                        'h-3.5 w-3.5 rounded-full',
                                        'bg-accent dark:bg-accent-dark' => $day['practised'],
                                        'border-2 border-dashed border-line dark:border-line-dark' => ! $day['practised'] && $day['future'],
                                        'bg-surface-sunken dark:bg-surface-sunken-dark' => ! $day['practised'] && ! $day['future'],
                                        'ring-2 ring-ink ring-offset-1 ring-offset-surface dark:ring-ink-dark dark:ring-offset-surface-dark' => $day['today'],
                                    ])></span>
                                    <span @class(['text-[10px] font-bold', 'text-ink dark:text-ink-dark' => $day['today'], 'text-ink-faint dark:text-ink-faint-dark' => ! $day['today']])>{{ $day['label'] }}</span>
                                </span>
                            @endforeach
                        </span>
                    </span>
                    <span class="flex shrink-0 flex-col items-end gap-0.5">
                        @if ($by === 'games')
                            <span class="text-xl font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ App\Models\PairGame::formatPoints($row['games']) }}<span class="text-sm text-ink-faint dark:text-ink-faint-dark"> pts</span></span>
                        @else
                            <span class="text-xl font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ $row['days'] }}<span class="text-sm text-ink-faint dark:text-ink-faint-dark">/7</span></span>
                        @endif
                        <span @class(['flex items-center gap-0.5 text-xs font-extrabold', 'text-accent-ink dark:text-accent-ink-dark' => $row['streak'] > 0, 'text-ink-faint dark:text-ink-faint-dark' => $row['streak'] === 0])>@svg('heroicon-s-fire', 'h-3.5 w-3.5'){{ $row['streak'] }}</span>
                    </span>
                @if ($row['me'])
                    </button>
                @else
                    </a>
                @endif
            </li>
        @endforeach
    </ol>

        <x-bottom-sheet show="open" label="Your highlight">
            <x-slot:title>Your highlight</x-slot:title>
            <form wire:submit="savePinnedHighlight" x-on:submit="open = false" class="space-y-3">
                <input type="text" wire:model="pinnedHighlight" maxlength="80" placeholder="e.g. Practicing every morning before work ☕" aria-label="Your highlight" class="w-full rounded-2xl border-2 border-accent bg-transparent px-3 py-2.5 text-sm text-ink dark:border-accent-dark dark:text-ink-dark">
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Friends see this on your page.</p>
                <button type="submit" class="w-full cursor-pointer rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white dark:bg-accent-dark">Save</button>
            </form>
            <a href="{{ route('friends.profile', $me) }}" wire:navigate class="block text-center text-sm font-bold text-accent-ink dark:text-accent-ink-dark">See your page ›</a>
        </x-bottom-sheet>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-2xl bg-surface px-4 py-2.5 text-xs font-bold text-ink-soft shadow-sm dark:bg-surface-dark dark:text-ink-soft-dark">
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-accent dark:bg-accent-dark"></span>Practised</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-full ring-2 ring-ink dark:ring-ink-dark"></span>Today</span>
        <span class="flex items-center gap-1.5"><span class="h-3 w-3 rounded-full bg-surface-sunken dark:bg-surface-sunken-dark"></span>Not yet</span>
    </div>
</div>
