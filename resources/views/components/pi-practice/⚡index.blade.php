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
     * Locked: these decide which day's practice is shown AND which day the
     * practice tick is filed under, so the browser must never be able
     * to rewrite them after mount() has checked them.
     */
    #[Locked]
    public string $missionCode = '';

    #[Locked]
    public int $day = 1;

    /**
     * Picks the prompt's random angles (see PiPrompts::dayTask()), so the
     * prompt stays the same while the page is open and changes on the
     * next visit.
     */
    #[Locked]
    public int $promptSeed = 0;

    /**
     * No arguments = the day the learner is pointed at (see PiPractice:
     * the day they just finished, or their current day). Any other day
     * opens too; one ahead of their program position is a preview (see
     * isAhead()): its prompt and plan show, but it can't be done yet.
     */
    public function mount(?string $requestedMission = null, ?int $requestedDay = null): void
    {
        $target = $requestedMission === null
            ? $this->target
            : ['missionCode' => $requestedMission, 'dayNumber' => $requestedDay ?? 1];

        $this->missionCode = $target['missionCode'];
        $this->day = $target['dayNumber'];
        $this->promptSeed = random_int(1, PHP_INT_MAX);
    }

    /**
     * "I did it": the learner's word is the tick, filed under the day on
     * screen and counted toward the streak (see User::activeDates()). The
     * day is re-checked here, not trusted from mount(): a day ahead is
     * refused.
     */
    public function markPracticed(): void
    {
        if ($this->isAhead) {
            return;
        }

        auth()->user()->recordPiPracticeToday($this->missionCode, $this->day);

        unset($this->practicedDaysByMission, $this->practicedDays, $this->isPracticed, $this->missionDays);
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

    /**
     * A day the learner's program hasn't reached yet.
     */
    #[Computed]
    public function isAhead(): bool
    {
        return app(ListeningPicks::class)->indexOf($this->missionCode, $this->day) > $this->currentIndex;
    }

    #[Computed]
    public function isToday(): bool
    {
        return $this->target['missionCode'] === $this->missionCode && $this->target['dayNumber'] === $this->day;
    }

    /**
     * @return array{title: string, goal: string, plan: list<string>, prompt: string}
     */
    #[Computed]
    public function task(): array
    {
        return app(PiPrompts::class)->dayTask(auth()->user(), $this->missionCode, $this->day, $this->promptSeed);
    }

    #[Computed]
    public function missionTitle(): string
    {
        return Mission::roadmapCatalog()[$this->missionCode]['title'];
    }

    /**
     * The days whose practice is done, by mission code.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function practicedDaysByMission(): array
    {
        return auth()->user()->piPracticedDaysByMission();
    }

    /**
     * The days of the mission on screen whose practice is done.
     *
     * @return list<int>
     */
    #[Computed]
    public function practicedDays(): array
    {
        return $this->practicedDaysByMission[$this->missionCode] ?? [];
    }

    /**
     * The tick on the banner and on this day's "I did it".
     */
    #[Computed]
    public function isPracticed(): bool
    {
        return in_array($this->day, $this->practicedDays, true);
    }

    /**
     * The four days of the mission on screen, for the quick day switcher
     * under the title, each marked against the learner's own position.
     *
     * @return list<array{number: int, title: string, state: string, viewing: bool, done: bool}>
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
                    'done' => in_array($number, $this->practicedDays, true),
                ];
            })
            ->values()
            ->all();
    }
};
?>

<div class="mx-auto max-w-2xl space-y-5 p-6">
    {{-- The mission's photo banner, the same as /listening: the way back
         to this day on the mission's page (where Daily talk sits beside
         the day's Lesson and Listening), the four days, and whether
         this day's practice is done. --}}
    <x-mission-banner
        :mission-code="$missionCode"
        title="Daily talk"
        icon="heroicon-o-microphone"
        :back-href="app(App\Services\MissionGrid::class)->pageUrl($missionCode, $day)"
        :back-label="$missionCode.' · '.$this->missionTitle.' · Day '.$day"
        :done-label="$this->isPracticed ? 'Practiced' : null"
    >
        <x-slot:actions>
            <x-usage-guide-button on-photo />
        </x-slot:actions>
        <p class="mt-0.5 text-sm text-white/80">{{ $this->task['title'] }} · {{ App\Services\PiPractice::MINUTES }} min</p>
        <x-mission-day-pills :mission-code="$missionCode" route-name="pi.practice" :days="$this->missionDays" />
    </x-mission-banner>

    @if (! $this->isToday)
        <a href="{{ route('pi.practice') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-accent-ink underline dark:text-accent-ink-dark">
            Back to today ({{ $this->target['missionCode'] }} · Day {{ $this->target['dayNumber'] }})
        </a>
    @endif

    @if ($this->isAhead)
        <x-preview-only-note />
    @endif

    @php
        $copiedKey = 'eos-pi-copied:'.$missionCode.':'.$day;
        $openPi = 'if (/Android/i.test(navigator.userAgent)) { window.location.href = '.App\Services\PiPrompts::piAndroidIntentJs().' }';
    @endphp

    {{-- One card: copy the prompt and talk in Pi, then "I did it" ticks the
         day. The page remembers the copy tap, so coming back from Pi finds
         the tick button even after a reload. --}}
    <div
        x-data="{
            copied: false,
            fullPrompt: false,
            showPrompt: false,
            init() {
                try { this.copied = localStorage.getItem({{ Illuminate\Support\Js::from($copiedKey) }}) === '1' } catch (e) {}
            },
            copyAndOpen() {
                navigator.clipboard?.writeText($refs.prompt.value);
                this.copied = true;
                try { localStorage.setItem({{ Illuminate\Support\Js::from($copiedKey) }}, '1') } catch (e) {}
                {{ $openPi }}
            },
        }"
        class="space-y-4"
    >
        <textarea x-ref="prompt" readonly hidden aria-hidden="true">{{ $this->task['prompt'] }}</textarea>

        <section class="card flex flex-col items-center gap-3 p-5 text-center" aria-label="Talk with Pi">
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-linear-to-br from-[#1fa98a] to-[#0e5c4b] text-xl font-extrabold text-white" aria-hidden="true">Pi</span>
            <h2 class="text-lg font-extrabold text-ink dark:text-ink-dark">Talk with Pi · {{ App\Services\PiPractice::MINUTES }} min</h2>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">A free chat, like two friends. Say "stop" when you're done.</p>
            @unless ($this->isAhead)
                <button type="button" x-on:click="copyAndOpen()" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-sm transition-colors hover:opacity-90 dark:bg-accent-dark">
                    @svg('heroicon-s-microphone', 'h-5 w-5') Copy &amp; open Pi
                </button>
                @if ($this->isPracticed)
                    <p class="text-sm font-extrabold text-success dark:text-success-dark">✓ Done for this day</p>
                @else
                    <button type="button" x-show="copied" x-cloak wire:click="markPracticed" wire:loading.attr="disabled" wire:target="markPracticed" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-success px-5 py-3 text-base font-extrabold text-success disabled:opacity-60 dark:border-success-dark dark:text-success-dark">
                        @svg('heroicon-s-check', 'h-5 w-5') I did it
                    </button>
                @endif
            @endunless
            <button type="button" x-on:click="showPrompt = true" class="cursor-pointer text-sm font-extrabold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">See prompt</button>
        </section>

        {{-- What Pi will do: the day's plan, the full prompt one tap further. --}}
        <x-bottom-sheet show="showPrompt" label="What Pi will do">
            <x-slot:title>What Pi will do</x-slot:title>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $this->task['goal'] }}</p>
            <ol class="space-y-2">
                @foreach ($this->task['plan'] as $planStep)
                    <li class="flex items-start gap-2.5 rounded-xl bg-surface-sunken px-3 py-2 text-sm font-semibold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">
                        <span class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2 border-line text-[10px] font-extrabold dark:border-line-dark">{{ $loop->iteration }}</span>
                        {{ $planStep }}
                    </li>
                @endforeach
            </ol>
            <button type="button" x-on:click="fullPrompt = ! fullPrompt" class="w-fit cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-bold text-ink-soft dark:border-line-dark dark:text-ink-soft-dark" x-text="fullPrompt ? 'Hide full prompt' : 'Show full prompt'">Show full prompt</button>
            <p x-show="fullPrompt" x-cloak class="max-h-60 overflow-y-auto rounded-xl bg-surface-sunken p-3 text-xs whitespace-pre-line text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">{{ $this->task['prompt'] }}</p>
            @unless ($this->isAhead)
                <button type="button" x-on:click="showPrompt = false; copyAndOpen()" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white dark:bg-accent-dark">Copy &amp; open Pi</button>
            @endunless
        </x-bottom-sheet>
    </div>
</div>
