<?php

use App\Models\User;
use App\Services\FriendActivity;
use App\Services\GameBadges;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public User $user;

    /** How many months before this one the calendar shows. */
    public int $monthsBack = 0;

    /** The day whose sheet is open (Y-m-d). */
    public ?string $day = null;

    public const MAX_MONTHS_BACK = 12;

    public function mount(User $user): void
    {
        abort_unless($this->canSee($user), 403);

        $this->user = $user;
    }

    private function canSee(User $user): bool
    {
        return $user->is(auth()->user()) || auth()->user()->canMessageWith($user);
    }

    public function previousMonth(): void
    {
        $this->monthsBack = min(self::MAX_MONTHS_BACK, $this->monthsBack + 1);
    }

    public function nextMonth(): void
    {
        $this->monthsBack = max(0, $this->monthsBack - 1);
    }

    /**
     * Opens one day's sheet: what was done that day.
     */
    public function pickDay(string $date): void
    {
        abort_unless($this->canSee($this->user), 403);

        $this->day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }
};
?>

@php
    $me = auth()->user();
    $isMe = $user->is($me);
    $activity = app(FriendActivity::class);
    $today = $me->localToday();
    $week = $activity->week($user, $activity->weekStart($me), $today);
    $daysThisWeek = collect($week)->where('practised', true)->count();
    $run = $user->currentInProgressMissionRun();
    $missionDays = $run?->dayProgress() ?? [];
    $currentDay = collect($missionDays)->search(fn (array $missionDay) => ! $missionDay['done']);
    $doneDays = collect($missionDays)->where('done', true)->count();
    $firstOfMonth = $today->copy()->startOfMonth()->subMonthsNoOverflow($monthsBack);
    $month = $activity->month($user, $me, $firstOfMonth, $today);
    $practisedToday = collect($week)->firstWhere('today', true)['practised'] ?? false;
    $streak = $user->currentStreak();
    $overall = (int) round(($user->missionsFinishedCount() * 100 + ($run?->progressPercent() ?? 0)) / 24);
    $earnedBadges = collect(app(GameBadges::class)->for($user))->where('earned', true);
    $timezone = $user->reminderTimezone();
@endphp

<div class="mx-auto max-w-2xl space-y-4 px-4 pt-3 pb-6 sm:px-6" x-data="{ daySheet: false }">
    <a href="{{ route('friends.board') }}" wire:navigate aria-label="Back to Board" class="-ms-2 inline-flex h-10 w-10 items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>

    <div class="flex items-center gap-3.5">
        <x-user-avatar :user="$user" class="h-16 w-16 text-xl" />
        <div class="min-w-0 flex-1">
            <h1 class="truncate font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $isMe ? 'You' : $user->name }}</h1>
            <p class="truncate text-xs font-semibold text-ink-faint dark:text-ink-faint-dark"><bdi>{{ $user->handle() }}</bdi></p>
            <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">
                @if ($run)
                    {{ $run->mission->code }} {{ $run->mission->title }}{{ $currentDay !== false ? ' · Day '.($currentDay + 1).' of '.count($missionDays) : '' }}
                @else
                    Not in a mission right now
                @endif
            </p>
            <p class="flex items-center gap-2 text-xs font-bold">
                <span @class(['text-success dark:text-success-dark' => $practisedToday, 'text-ink-faint dark:text-ink-faint-dark' => ! $practisedToday])>{{ $practisedToday ? 'Practised today' : 'Not practised today yet' }}</span>
                <span @class(['flex items-center gap-0.5', 'text-accent-ink dark:text-accent-ink-dark' => $streak > 0, 'text-ink-faint dark:text-ink-faint-dark' => $streak === 0])>@svg('heroicon-s-fire', 'h-3.5 w-3.5'){{ $streak }}</span>
            </p>
        </div>
        <x-progress-ring :percent="$overall" :size="44" />
    </div>

    @if ($user->pinned_highlight)
        <p class="rounded-2xl bg-surface px-4 py-2.5 text-sm text-ink-soft italic shadow-sm dark:bg-surface-dark dark:text-ink-soft-dark">“{{ $user->pinned_highlight }}”</p>
    @endif

    @unless ($isMe)
        <div class="grid grid-cols-2 gap-2.5">
            <a href="{{ route('games.index') }}" wire:navigate class="flex h-12 items-center justify-center rounded-2xl bg-accent text-base font-extrabold text-white shadow-[0_3px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Play a game</a>
            <a href="{{ route('friends.conversation', $user) }}" wire:navigate class="flex h-12 items-center justify-center rounded-2xl bg-surface text-base font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark">Message</a>
        </div>
    @endunless

    {{-- This week, Saturday to Friday: each day named, practised days filled (a mission day tagged with its day). --}}
    <section class="space-y-3 rounded-3xl bg-surface p-4 shadow-sm dark:bg-surface-dark">
        <div class="flex items-baseline justify-between">
            <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">This week</h2>
            <span class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $daysThisWeek }} of 7 days</span>
        </div>
        <div class="grid grid-cols-7 gap-1">
            @foreach ($week as $weekDay)
                <button type="button" wire:key="week-{{ $weekDay['date'] }}" @disabled($weekDay['future']) wire:click="pickDay('{{ $weekDay['date'] }}')" x-on:click="daySheet = true" aria-label="{{ $weekDay['label'] }} {{ $weekDay['day'] }}{{ $weekDay['practised'] ? ', practised' : '' }}" class="flex cursor-pointer flex-col items-center gap-1 disabled:cursor-default">
                    <span @class(['text-[11px] font-extrabold', 'text-ink dark:text-ink-dark' => $weekDay['today'], 'text-ink-faint dark:text-ink-faint-dark' => ! $weekDay['today']])>{{ $weekDay['label'] }}</span>
                    <span @class([
                        'flex h-9 w-9 items-center justify-center rounded-full text-sm font-extrabold',
                        'bg-accent text-white dark:bg-accent-dark' => $weekDay['missionDay'],
                        'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $weekDay['practised'] && ! $weekDay['missionDay'],
                        'border-2 border-dashed border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark' => $weekDay['future'],
                        'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => ! $weekDay['practised'] && ! $weekDay['future'],
                        'ring-2 ring-ink ring-offset-2 ring-offset-surface dark:ring-ink-dark dark:ring-offset-surface-dark' => $weekDay['today'],
                    ])>{{ $weekDay['day'] }}</span>
                    <span class="h-3 text-[10px] font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $weekDay['tag'] }}</span>
                </button>
            @endforeach
        </div>
    </section>

    @if ($run)
        {{-- The mission's path: each day, done ones with the date they were finished. --}}
        <section class="space-y-3 rounded-3xl bg-surface p-4 shadow-sm dark:bg-surface-dark">
            <div class="flex items-baseline justify-between">
                <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">{{ $run->mission->code }} · {{ $run->mission->title }}</h2>
                <span class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $doneDays }} of {{ count($missionDays) }} days done</span>
            </div>
            <div class="grid grid-cols-4 gap-2" aria-label="Mission days">
                @foreach ($missionDays as $index => $missionDay)
                    @php $isCurrent = $index === $currentDay; @endphp
                    <div wire:key="path-{{ $index }}" @class([
                        'flex flex-col items-center rounded-2xl border-2 px-1 py-2',
                        'border-transparent bg-accent text-white dark:bg-accent-dark' => $missionDay['done'],
                        'border-accent bg-accent-soft text-ink dark:border-accent-dark dark:bg-accent-soft-dark dark:text-ink-dark' => $isCurrent,
                        'border-transparent bg-surface-sunken text-ink-faint dark:bg-surface-sunken-dark dark:text-ink-faint-dark' => ! $missionDay['done'] && ! $isCurrent,
                    ])>
                        <span class="text-[11px] font-extrabold opacity-80">Day</span>
                        <span class="text-lg leading-tight font-extrabold">{{ $index + 1 }}</span>
                        <span class="text-[10px] font-bold opacity-80">{{ $missionDay['done'] && $missionDay['completedAt'] ? $missionDay['completedAt']->copy()->setTimezone($timezone)->format('M j') : ($isCurrent ? 'now' : '·') }}</span>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- A month (Saturday first): mission days, other practice, games with me. --}}
    <section class="space-y-2.5 rounded-3xl bg-surface p-4 shadow-sm dark:bg-surface-dark">
        <div class="flex items-center justify-between">
            <button type="button" wire:click="previousMonth" @disabled($monthsBack >= 12) aria-label="Previous month" class="inline-flex h-10 w-11 cursor-pointer items-center justify-center rounded-full text-ink-soft disabled:opacity-30 dark:text-ink-soft-dark">@svg('heroicon-o-chevron-left', 'h-5 w-5')</button>
            <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">{{ $firstOfMonth->format('F Y') }}</h2>
            <button type="button" wire:click="nextMonth" @disabled($monthsBack === 0) aria-label="Next month" class="inline-flex h-10 w-11 cursor-pointer items-center justify-center rounded-full text-ink-soft disabled:opacity-30 dark:text-ink-soft-dark">@svg('heroicon-o-chevron-right', 'h-5 w-5')</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center">
            @foreach (array_slice($month, 0, 7) as $head)
                <span class="text-[11px] font-extrabold text-ink-faint dark:text-ink-faint-dark">{{ $head['label'] }}</span>
            @endforeach
            @foreach ($month as $cell)
                @if (! $cell['inMonth'])
                    <span wire:key="cell-{{ $cell['date'] }}" aria-hidden="true"></span>
                @else
                    <button type="button" wire:key="cell-{{ $cell['date'] }}" @disabled($cell['future']) wire:click="pickDay('{{ $cell['date'] }}')" x-on:click="daySheet = true" aria-label="{{ $cell['date'] }}{{ $cell['practised'] ? ', practised' : '' }}" @class([
                        'relative flex h-9 cursor-pointer items-center justify-center rounded-xl text-[13px] font-extrabold disabled:cursor-default',
                        'bg-accent text-white dark:bg-accent-dark' => $cell['missionDay'],
                        'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $cell['practised'] && ! $cell['missionDay'],
                        'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => ! $cell['practised'] && ! $cell['future'],
                        'text-ink-faint dark:text-ink-faint-dark' => $cell['future'],
                        'ring-2 ring-ink dark:ring-ink-dark' => $cell['today'],
                    ])>
                        {{ $cell['day'] }}
                        @if ($cell['withViewer'])
                            <span class="absolute bottom-1 h-1 w-1 rounded-full {{ $cell['missionDay'] ? 'bg-white' : 'bg-ink dark:bg-ink-dark' }}"></span>
                        @endif
                    </button>
                @endif
            @endforeach
        </div>
        <div class="flex flex-wrap gap-x-3.5 gap-y-1 text-xs font-bold text-ink-soft dark:text-ink-soft-dark">
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[3px] bg-accent dark:bg-accent-dark"></span>Mission day</span>
            <span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-[3px] bg-success-soft dark:bg-success-soft-dark"></span>Other practice</span>
            @unless ($isMe)
                <span class="flex items-center gap-1.5"><span class="h-1.5 w-1.5 rounded-full bg-ink dark:bg-ink-dark"></span>Game with you</span>
            @endunless
        </div>
    </section>

    @php $together = $isMe ? null : $activity->together($me, $user); @endphp
    <section class="space-y-3 rounded-3xl bg-surface p-4 shadow-sm dark:bg-surface-dark">
        @if ($together)
            <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">You and {{ $user->name }}</h2>
            <div class="grid grid-cols-3 gap-2">
                @foreach ([[$together['games'], Str::plural('game', $together['games'])], [$together['daysInARow'], 'days in a row'], [$together['bestTaboo'] === null ? '—' : App\Models\PairGame::formatPoints($together['bestTaboo']), 'best Taboo']] as [$number, $label])
                    <div class="rounded-2xl bg-surface-sunken p-2.5 dark:bg-surface-sunken-dark">
                        <p class="text-xl font-extrabold text-ink tabular-nums dark:text-ink-dark">{{ $number }}</p>
                        <p class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        @endif
        <div>
            <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">{{ $isMe ? 'Your' : $user->name.'’s' }} badges</p>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @forelse ($earnedBadges as $badge)
                    <span wire:key="friend-badge-{{ $badge['key'] }}" class="flex items-center gap-1 rounded-full bg-accent-soft px-2.5 py-1 text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">@svg($badge['icon'], 'h-3.5 w-3.5'){{ $badge['name'] }}</span>
                @empty
                    <span class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">No badges yet.</span>
                @endforelse
            </div>
        </div>
    </section>

    {{-- One day: what was done, never the answers. --}}
    <x-bottom-sheet show="daySheet" label="A day">
        <div wire:loading.flex wire:target="pickDay" class="items-center justify-center py-8">
            <span class="h-6 w-6 animate-spin rounded-full border-2 border-accent border-t-transparent dark:border-accent-dark dark:border-t-transparent"></span>
        </div>
        @if ($day)
            @php
                $items = $activity->dayItems($user, $me, $day);
                $missionItems = collect($items)->where('kind', 'mission');
                $otherItems = collect($items)->where('kind', '!=', 'mission');
                $icons = ['listening' => 'heroicon-o-musical-note', 'pi' => 'heroicon-o-chat-bubble-left-ellipsis', 'review' => 'heroicon-o-bolt', 'pair' => 'heroicon-o-user-group', 'game' => 'heroicon-o-puzzle-piece'];
            @endphp
            <div wire:loading.remove wire:target="pickDay" class="space-y-3">
                <div class="flex items-center gap-3">
                    <x-user-avatar :user="$user" class="h-11 w-11 text-sm" />
                    <div>
                        <p class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ Illuminate\Support\Carbon::parse($day)->format('D j M') }}</p>
                        <p class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $isMe ? 'You' : $user->name }} · {{ count($items) === 0 ? 'nothing done' : count($items).' '.Str::plural('thing', count($items)).' done' }}</p>
                    </div>
                </div>
                @foreach ($missionItems as $item)
                    <div wire:key="day-mission-{{ $loop->index }}" class="rounded-2xl bg-accent p-3.5 text-white dark:bg-accent-dark">
                        <p class="text-[11px] font-extrabold tracking-wider">MISSION DAY</p>
                        <p class="text-lg font-extrabold">{{ $item['title'] }}</p>
                        <p class="text-xs font-bold opacity-90">{{ $item['line'] }}</p>
                    </div>
                @endforeach
                @foreach ($otherItems as $item)
                    <div wire:key="day-item-{{ $loop->index }}" class="flex items-center gap-3 rounded-2xl bg-surface-sunken px-3.5 py-3 dark:bg-surface-sunken-dark">
                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-surface text-accent-ink dark:bg-surface-dark dark:text-accent-ink-dark">@svg($icons[$item['kind']] ?? 'heroicon-o-check', 'h-5 w-5')</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-extrabold text-ink dark:text-ink-dark">{{ $item['title'] }}</span>
                            <span class="block truncate text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $item['line'] }}</span>
                        </span>
                        <span class="text-success dark:text-success-dark">@svg('heroicon-s-check', 'h-5 w-5')</span>
                    </div>
                @endforeach
                <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Only what was done, never the answers.</p>
            </div>
        @endif
    </x-bottom-sheet>
</div>
