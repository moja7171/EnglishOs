<?php

use App\Models\Mission;
use App\Services\ListeningPicks;
use App\Services\PiPractice;
use App\Services\PiPrompts;
use App\Services\ProgramPlanner;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    /**
     * Locked: these decide which day's practice is shown AND which day an
     * "I practiced" tick is filed under, so the browser must never be able
     * to rewrite them after mount() has checked them.
     */
    #[Locked]
    public string $missionCode = '';

    #[Locked]
    public int $day = 1;

    /**
     * No arguments = the day the learner is pointed at (see PiPractice:
     * the day they just finished, or their current day). A specific day is
     * allowed only if the learner has reached it: days ahead of their
     * program position are sent back to today, same "nothing is skippable"
     * idea as the rest of the app (but never a calendar lock).
     */
    public function mount(?string $requestedMission = null, ?int $requestedDay = null): void
    {
        if ($requestedMission === null) {
            $target = $this->target;
        } else {
            $target = ['missionCode' => $requestedMission, 'dayNumber' => $requestedDay ?? 1];

            if (app(ListeningPicks::class)->indexOf($target['missionCode'], $target['dayNumber']) > $this->currentIndex) {
                $this->redirectRoute('pi.practice', navigate: true);

                return;
            }
        }

        $this->missionCode = $target['missionCode'];
        $this->day = $target['dayNumber'];
    }

    /**
     * The learner's "I practiced" tick — counts today toward the streak
     * (see User::activeDates()), filed under the day on screen. The day
     * is re-checked here, not trusted from mount().
     */
    public function markPracticed(): void
    {
        if (app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex) {
            return;
        }

        auth()->user()->recordPiPracticeToday($this->missionCode, $this->day);

        unset($this->practicedToday);
    }

    #[Computed]
    public function program(): array
    {
        return app(ProgramPlanner::class)->plan(auth()->user());
    }

    /**
     * The day the practice entry points open — see PiPractice::targetFor().
     * After the whole program is done it falls back to the last day.
     *
     * @return array{missionCode: string, dayNumber: int}
     */
    #[Computed]
    public function target(): array
    {
        return app(PiPractice::class)->targetFor(auth()->user(), $this->program['today'])
            ?? ['missionCode' => 'M24', 'dayNumber' => 4];
    }

    /**
     * Program position of the learner's own current day (0-95). Once the
     * whole program is done every day counts as reached.
     */
    #[Computed]
    public function currentIndex(): int
    {
        $picks = app(ListeningPicks::class);
        $current = $picks->dayFor($this->program['today']);

        return $current === null
            ? ProgramPlanner::TOTAL_DAYS - 1
            : $picks->indexOf($current['missionCode'], $current['dayNumber']);
    }

    #[Computed]
    public function isToday(): bool
    {
        return $this->target['missionCode'] === $this->missionCode && $this->target['dayNumber'] === $this->day;
    }

    /**
     * @return array{role: string, roleLabel: string, title: string, goal: string, instruction: string, prompt: string}
     */
    #[Computed]
    public function task(): array
    {
        return app(PiPrompts::class)->dayTask($this->missionCode, $this->day);
    }

    #[Computed]
    public function missionTitle(): string
    {
        return Mission::roadmapCatalog()[$this->missionCode]['title'];
    }

    #[Computed]
    public function practicedToday(): bool
    {
        return auth()->user()->hasPracticedWithPiToday();
    }

    /**
     * The four days of the mission on screen, for the quick day switcher
     * under the title, each marked against the learner's own position.
     *
     * @return list<array{number: int, title: string, state: string, viewing: bool}>
     */
    #[Computed]
    public function missionDays(): array
    {
        $picks = app(ListeningPicks::class);
        $viewing = $picks->indexOf($this->missionCode, $this->day);
        $targetIndex = $picks->indexOf($this->target['missionCode'], $this->target['dayNumber']);

        return collect(PiPrompts::DAYS)
            ->map(function (array $day, int $number) use ($picks, $viewing, $targetIndex) {
                $index = $picks->indexOf($this->missionCode, $number);

                return [
                    'number' => $number,
                    'title' => $day['title'],
                    'state' => match (true) {
                        $index > $this->currentIndex => 'future',
                        $index === $targetIndex => 'today',
                        default => 'past',
                    },
                    'viewing' => $index === $viewing,
                ];
            })
            ->values()
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
            @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5') Missions
        </a>
        <div class="mt-3 flex items-center gap-3">
            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-white dark:bg-accent-dark">
                @svg('heroicon-o-microphone', 'h-5 w-5')
            </span>
            <div>
                <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->isToday ? 'Voice practice today' : 'Voice practice' }}</h1>
                <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $missionCode }} · {{ $this->missionTitle }} · Day {{ $day }} of 4</p>
            </div>
        </div>
        <nav class="mt-3 flex flex-wrap items-center gap-1.5" aria-label="Days of {{ $missionCode }}">
            @foreach ($this->missionDays as $dayInfo)
                @php
                    $pill = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold';
                @endphp
                @if ($dayInfo['state'] === 'future')
                    <span
                        class="{{ $pill }} cursor-not-allowed border-dashed border-line text-ink-faint dark:border-line-dark dark:text-ink-faint-dark"
                        title="Not reached yet"
                        aria-label="Day {{ $dayInfo['number'] }}, not reached yet"
                    >Day {{ $dayInfo['number'] }} · {{ $dayInfo['title'] }}</span>
                @else
                    <a
                        href="{{ route('pi.practice', [$missionCode, $dayInfo['number']]) }}"
                        wire:navigate
                        @if ($dayInfo['viewing']) aria-current="page" @endif
                        @class([
                            $pill,
                            'border-ink bg-ink text-ground dark:border-ink-dark dark:bg-ink-dark dark:text-ground-dark' => $dayInfo['viewing'],
                            'border-accent text-accent-ink dark:border-accent-dark dark:text-accent-ink-dark' => ! $dayInfo['viewing'] && $dayInfo['state'] === 'today',
                            'border-line text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark' => ! $dayInfo['viewing'] && $dayInfo['state'] !== 'today',
                        ])
                    >Day {{ $dayInfo['number'] }} · {{ $dayInfo['title'] }}{{ $dayInfo['state'] === 'today' ? ' · today' : '' }}</a>
                @endif
            @endforeach
        </nav>
        @if (! $this->isToday)
            <a href="{{ route('pi.practice') }}" wire:navigate class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">
                Back to today ({{ $this->target['missionCode'] }} · Day {{ $this->target['dayNumber'] }})
            </a>
        @endif
    </header>

    <section class="space-y-1">
        <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $this->task['title'] }} · about 10 minutes, voice only</p>
        <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $this->task['goal'] }}</p>
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">The last thing of your day, after the mission steps. It is never a step and never blocks anything.</p>
    </section>

    <section class="card space-y-3 p-4" aria-label="How to practice">
        <ol class="list-decimal space-y-1.5 ps-5 text-sm text-ink-soft dark:text-ink-soft-dark">
            <li>
                Open the voice AI app you like best —
                <a href="https://pi.ai" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Pi</a>,
                <a href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Gemini Live</a>,
                or any other one you already use.
            </li>
            <li>Go to your <span class="font-semibold text-ink dark:text-ink-dark">{{ $this->task['roleLabel'] }}</span> chat there.</li>
            <li>Copy the prompt below, paste it in, and talk out loud.</li>
        </ol>

        <x-pi-practice-card :role-label="$this->task['roleLabel']" :task="$this->task" :rows="5" />

        @if ($day === 4)
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Afterwards, save the better phrases you liked in My Words.</p>
        @endif

        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
            Haven't made your 3 chats yet?
            <a href="{{ route('pi.setup') }}" wire:navigate class="font-semibold underline">Voice practice setup</a>
        </p>
    </section>

    <section class="flex flex-wrap items-center gap-3 rounded-xl px-1">
        @if ($this->practicedToday)
            <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-4 py-2 text-sm font-semibold text-success dark:bg-success-soft-dark dark:text-success-dark">
                @svg('heroicon-s-check', 'h-4 w-4') Practiced today
            </span>
        @else
            <button
                type="button"
                wire:click="markPracticed"
                wire:loading.attr="disabled"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-5 py-2 text-sm font-semibold text-ink transition-colors hover:bg-surface-sunken disabled:opacity-60 dark:border-line-dark dark:text-ink-dark dark:hover:bg-surface-sunken-dark"
            >I practiced @svg('heroicon-s-check', 'h-4 w-4')</button>
            <span class="text-xs text-ink-faint dark:text-ink-faint-dark">Counts toward your streak</span>
        @endif
    </section>
</div>
