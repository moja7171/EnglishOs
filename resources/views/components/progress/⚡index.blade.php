<?php

use App\Models\Mission;
use App\Models\SpeakingPrompt;
use App\Services\FinishLine;
use App\Services\ProgramPlanner;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /** How many "Your voice" rows show before "See all". */
    public const VOICES_SHOWN = 3;

    /**
     * Streak, words, this week and the 12-week strip — all read from data
     * the rest of the app already keeps.
     *
     * @return array{currentStreak: int, vocabularyCount: int, calendar: list<array{date: string, label: string, active: bool, future: bool}>, activeDaysThisWeek: int, daysUntilNextMilestone: ?int, nextMilestone: ?int}
     */
    #[Computed]
    public function progressStats(): array
    {
        $user = auth()->user();

        return [
            'currentStreak' => $user->currentStreak(),
            'vocabularyCount' => $user->vocabularyWordsSelected()->count(),
            'calendar' => $user->activityCalendar(),
            'activeDaysThisWeek' => $user->activeDaysThisWeek(),
            'daysUntilNextMilestone' => $user->daysUntilNextMilestone(),
            'nextMilestone' => $user->nextStreakMilestone(),
        ];
    }

    /**
     * Where the learner is in the 96 program days, the mission they are on
     * and the finish date if they do one program day a day from now on (see FinishLine).
     *
     * @return array{programDay: int, totalDays: int, daysDelta: int, started: bool, finished: bool, mission: ?Mission, finishDate: ?string}
     */
    #[Computed]
    public function program(): array
    {
        $learner = auth()->user();
        $plan = app(ProgramPlanner::class)->plan($learner);
        $finished = $plan['today']['kind'] === 'finished';
        $finishLine = app(FinishLine::class);
        $forecast = $finished ? null : $finishLine->forecast($learner);

        return [
            'programDay' => $plan['programDay'],
            'totalDays' => $plan['totalDays'],
            'daysDelta' => $plan['daysDelta'],
            'started' => $plan['started'],
            'finished' => $finished,
            'mission' => $plan['today']['mission'] ?? null,
            'finishDate' => $forecast === null ? null : $finishLine->persianDate($forecast['steadyDate']),
        ];
    }

    #[Computed]
    public function totalPracticeMinutes(): int
    {
        return auth()->user()->totalPracticeMinutes();
    }

    #[Computed]
    public function totalListens(): int
    {
        return auth()->user()->totalListensCount();
    }

    /**
     * Speaking questions the learner has answered aloud, newest first, each
     * with their last recording (see the speaking card in Daily Review).
     *
     * @return Collection<int, SpeakingPrompt>
     */
    #[Computed]
    public function voices(): Collection
    {
        return auth()->user()->speakingPrompts()
            ->whereNotNull('last_recording_url')
            ->latest('last_reviewed_at')
            ->latest('id')
            ->get();
    }

    /**
     * Mistakes the learner has stopped making, then the ones that just
     * haven't come up lately (see User::masteredErrorPatterns()).
     */
    #[Computed]
    public function fixedMistakes(): Collection
    {
        $user = auth()->user();

        return $user->masteredErrorPatterns()->concat($user->fadingErrorPatterns());
    }

    /** One tap on a goal option: '' clears the goal, '1'-'7' sets it. */
    public function setWeeklyGoal(string $days): void
    {
        if ($days !== '' && ! in_array($days, ['1', '2', '3', '4', '5', '6', '7'], true)) {
            return;
        }

        auth()->user()->update(['weekly_goal_days' => $days !== '' ? (int) $days : null]);

        unset($this->progressStats);
    }
};
?>

@php
    $program = $this->program;
    $stats = $this->progressStats;
    $delta = $program['daysDelta'];
    $weeklyGoal = auth()->user()->weekly_goal_days ? (int) auth()->user()->weekly_goal_days : null;
    $thisWeek = array_slice($stats['calendar'], -7);
    $weekdayInitials = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
    $minutes = $this->totalPracticeMinutes;
    $tile = 'rounded-2xl bg-surface px-1 py-2.5 text-center dark:bg-surface-dark';
    $tileNumber = 'block text-lg font-extrabold text-ink tabular-nums dark:text-ink-dark';
    $tileLabel = 'text-[11px] font-semibold text-ink-faint dark:text-ink-faint-dark';
    $sectionTitle = 'text-sm font-extrabold text-ink dark:text-ink-dark';
@endphp

{{-- One scroll, no tabs: where you are and when you finish, four numbers,
     this week against the goal, the last 12 weeks, your recorded answers
     and the mistakes you fixed — a card only shows when it has something. --}}
<div class="stagger-children mx-auto max-w-2xl space-y-3 p-4 sm:p-6">
    <header class="flex items-center gap-2">
        {{-- Back goes where the learner came from (Profile, the streak sheet, Home), not always Home. --}}
        <a href="{{ route('home') }}" x-data x-on:click="if (history.length > 1) { $event.preventDefault(); history.back() }" aria-label="Back" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Progress</h1>
    </header>

    <section class="card flex items-center gap-4 rounded-3xl p-4">
        <x-progress-ring :percent="$program['totalDays'] > 0 ? round($program['programDay'] / $program['totalDays'] * 100) : 0" :size="84" :stroke-width="8">
            <span class="text-center text-base leading-tight font-extrabold">Day {{ $program['programDay'] }} <span class="block text-[10px] font-semibold text-ink-faint dark:text-ink-faint-dark">of {{ $program['totalDays'] }}</span></span>
        </x-progress-ring>
        <div class="min-w-0 flex-1 space-y-1">
            @if ($program['finished'])
                <p class="font-extrabold text-ink dark:text-ink-dark">All 24 missions done 🎉</p>
            @elseif ($program['mission'])
                <p class="truncate font-extrabold text-ink dark:text-ink-dark">{{ $program['mission']->code }} · {{ $program['mission']->title }}</p>
            @endif
            @if ($program['finishDate'])
                <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">🏁 Finish: <span dir="rtl" class="font-fa">{{ $program['finishDate'] }}</span></p>
            @endif
            @if ($program['started'] && ! $program['finished'])
                <span @class([
                    'inline-block rounded-full px-2 py-0.5 text-[11px] font-bold',
                    'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => $delta >= 0,
                    'bg-warning-soft text-warning-ink' => $delta < 0,
                ])>
                    @if ($delta > 0) {{ $delta }} {{ Str::plural('day', $delta) }} ahead
                    @elseif ($delta === 0) On track
                    @else {{ abs($delta) }} {{ Str::plural('day', abs($delta)) }} behind
                    @endif
                </span>
            @endif
        </div>
    </section>

    <section class="grid grid-cols-4 gap-2">
        <div class="{{ $tile }}"><span class="{{ $tileNumber }}">🔥 {{ $stats['currentStreak'] }}</span> <span class="{{ $tileLabel }}">streak</span></div>
        <div class="{{ $tile }}"><span class="{{ $tileNumber }}">{{ $stats['vocabularyCount'] }}</span> <span class="{{ $tileLabel }}">words</span></div>
        <div class="{{ $tile }}"><span class="{{ $tileNumber }}">{{ $minutes >= 60 ? intdiv($minutes, 60).'h' : $minutes.'m' }}</span> <span class="{{ $tileLabel }}">practice</span></div>
        <div class="{{ $tile }}"><span class="{{ $tileNumber }}">{{ $this->totalListens }}</span> <span class="{{ $tileLabel }}">listens</span></div>
    </section>

    <section class="card space-y-3 rounded-3xl p-4" x-data="{ picker: false }">
        <div class="flex items-center justify-between gap-3">
            <h2 class="{{ $sectionTitle }}">This week</h2>
            <button type="button" x-on:click="picker = true" class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent-soft px-3 py-1 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                {{ $weeklyGoal ? 'Goal: '.$weeklyGoal.' '.Str::plural('day', $weeklyGoal) : 'Set a goal' }} @svg('heroicon-o-chevron-down', 'h-3 w-3')
            </button>
        </div>

        <div class="grid grid-cols-7 gap-1.5">
            @foreach ($thisWeek as $index => $day)
                @php $isToday = $day['date'] === auth()->user()->localToday()->toDateString(); @endphp
                <div wire:key="week-{{ $day['date'] }}" class="flex flex-col items-center gap-1" title="{{ $day['label'] }}{{ $day['active'] ? ' — practiced' : '' }}">
                    <span class="text-[10px] font-bold text-ink-faint dark:text-ink-faint-dark">{{ $weekdayInitials[$index] }}</span>
                    <span @class([
                        'inline-flex h-7 w-7 items-center justify-center rounded-full border-2',
                        'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' => $day['active'],
                        'border-dashed border-accent dark:border-accent-dark' => ! $day['active'] && $isToday,
                        'border-line dark:border-line-dark' => ! $day['active'] && ! $isToday,
                    ])>
                        @if ($day['active'])
                            @svg('heroicon-s-check', 'h-3.5 w-3.5')
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        @if ($weeklyGoal)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $stats['activeDaysThisWeek'] }} of {{ $weeklyGoal }} days this week</p>
        @endif
        @if ($stats['daysUntilNextMilestone'] !== null && $stats['currentStreak'] > 0)
            <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $stats['daysUntilNextMilestone'] }} more {{ Str::plural('day', $stats['daysUntilNextMilestone']) }} to your {{ $stats['nextMilestone'] }}-day badge 🔥</p>
        @endif

        <x-bottom-sheet show="picker" label="Weekly goal">
            <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">Days a week</p>
            <div class="mt-3 grid grid-cols-7 gap-1.5">
                @foreach (range(1, 7) as $n)
                    <button type="button" wire:key="goal-{{ $n }}" wire:click="setWeeklyGoal('{{ $n }}')" x-on:click="picker = false" @class([
                        'h-11 cursor-pointer rounded-xl text-base font-extrabold transition-colors',
                        'bg-accent text-white dark:bg-accent-dark' => $weeklyGoal === $n,
                        'bg-surface-sunken text-ink dark:bg-surface-sunken-dark dark:text-ink-dark' => $weeklyGoal !== $n,
                    ])>{{ $n }}</button>
                @endforeach
            </div>
            @if ($weeklyGoal)
                <button type="button" wire:click="setWeeklyGoal('')" x-on:click="picker = false" class="mt-3 w-full cursor-pointer py-2 text-sm font-bold text-ink-faint dark:text-ink-faint-dark">No goal</button>
            @endif
        </x-bottom-sheet>
    </section>

    <section class="card rounded-3xl p-4">
        <h2 class="{{ $sectionTitle }}">Last 12 weeks</h2>
        <x-activity-heatmap :calendar="$stats['calendar']" caption="" class="-mt-1" />
    </section>

    @if ($this->voices->isNotEmpty())
        <section class="card space-y-1 rounded-3xl p-4" x-data="{ all: false, playing: null, audio: null, play(url) { if (this.playing === url) { this.audio.pause(); this.playing = null; return; } this.audio?.pause(); this.audio = new Audio(url); this.audio.onended = () => this.playing = null; this.audio.play(); this.playing = url; } }">
            <h2 class="{{ $sectionTitle }} pb-1">Your voice</h2>
            @foreach ($this->voices as $voice)
                <div wire:key="voice-{{ $voice->id }}" @if ($loop->index >= $this::VOICES_SHOWN) x-show="all" x-cloak @endif class="flex items-center gap-3 py-1.5">
                    <button type="button" x-on:click="play(@js($voice->last_recording_url))" x-bind:aria-label="playing === @js($voice->last_recording_url) ? 'Pause' : 'Play my answer'" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        <span x-show="playing !== @js($voice->last_recording_url)">@svg('heroicon-s-play', 'h-4 w-4')</span>
                        <span x-show="playing === @js($voice->last_recording_url)" x-cloak>@svg('heroicon-s-pause', 'h-4 w-4')</span>
                    </button>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $voice->prompt }}</p>
                        <p class="text-[11px] font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $voice->mission_code }}@if ($voice->last_reviewed_at) · {{ $voice->last_reviewed_at->diffForHumans() }}@endif</p>
                    </div>
                </div>
            @endforeach
            @if ($this->voices->count() > $this::VOICES_SHOWN)
                <button type="button" x-show="! all" x-on:click="all = true" class="cursor-pointer pt-1 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">See all {{ $this->voices->count() }} ›</button>
            @endif
        </section>
    @endif

    @if ($this->fixedMistakes->isNotEmpty())
        <section class="card space-y-2 rounded-3xl p-4">
            <h2 class="text-sm font-extrabold text-success dark:text-success-dark">✓ Mistakes you fixed</h2>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($this->fixedMistakes as $pattern)
                    <span wire:key="fixed-{{ $pattern->id }}" class="max-w-full truncate rounded-full bg-success-soft px-2.5 py-1 text-xs font-bold text-success dark:bg-success-soft-dark dark:text-success-dark">{{ $pattern->last_error }} → {{ $pattern->last_correction }}</span>
                @endforeach
            </div>
        </section>
    @endif
</div>
