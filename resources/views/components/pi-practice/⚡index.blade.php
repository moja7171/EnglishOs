<?php

use App\Livewire\Concerns\GivesConversationFeedback;
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
    use GivesConversationFeedback;

    /**
     * Locked: these decide which day's practice is shown AND which day an
     * "I practiced" tick is filed under, so the browser must never be able
     * to rewrite them after mount() has checked them.
     */
    #[Locked]
    public string $missionCode = '';

    #[Locked]
    public int $day = 1;

    /** The voice-AI chat the learner copied and pasted back in, for feedback. */
    public string $pastedChat = '';

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

    /**
     * Pi keeps a written transcript of a voice chat; the learner copies it
     * and pastes it here. Speaker names may or may not come along, so the
     * AI is told how to tell the two sides apart.
     */
    public function checkPastedChat(): void
    {
        $this->validate(['pastedChat' => ['required', 'string', 'min:40', 'max:12000']], attributes: ['pastedChat' => 'chat']);

        $this->requestConversationFeedback(
            $this->pastedChat,
            situation: 'a voice conversation the learner had with an AI speaking partner (such as Pi), copied from '
                .'the app and pasted in. Speaker names may be missing — the AI\'s turns are usually longer and '
                .'ask questions or give corrections; judge ONLY the learner\'s own turns. It was spoken and then '
                .'transcribed, so ignore punctuation, capital letters and filler words.',
        );
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
     * @return array{title: string, goal: string, plan: list<string>, prompt: string}
     */
    #[Computed]
    public function task(): array
    {
        return app(PiPrompts::class)->dayTask(auth()->user(), $this->missionCode, $this->day);
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

    <section class="card space-y-2 p-4" aria-label="Today's plan">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Today's plan</p>
        <ol class="list-decimal space-y-1 ps-5 text-sm text-ink-soft dark:text-ink-soft-dark">
            @foreach ($this->task['plan'] as $planStep)
                <li>{{ $planStep }}</li>
            @endforeach
        </ol>
    </section>

    <section class="card space-y-3 p-4" aria-label="How to practice">
        <ol class="list-decimal space-y-1.5 ps-5 text-sm text-ink-soft dark:text-ink-soft-dark">
            <li>
                Open the voice AI app you like best —
                <a href="https://pi.ai" target="_blank" rel="noopener noreferrer" x-data x-on:click="if (/Android/i.test(navigator.userAgent)) { $event.preventDefault(); window.location.href = {{ App\Services\PiPrompts::piAndroidIntentJs() }} }" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Pi</a>,
                <a href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Gemini Live</a>,
                or any other one you already use.
            </li>
            <li>Turn on voice mode first.</li>
            <li>Copy the prompt below, send it, and talk out loud.</li>
        </ol>

        <x-pi-practice-card :task="$this->task" :rows="6" />

        <div class="space-y-1 text-xs text-ink-faint dark:text-ink-faint-dark">
            <p>
                Say these any time:
                @foreach (PiPrompts::CONTROL_WORDS as $word => $meaning)
                    <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $word }}</span> ({{ $meaning }}){{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>
            <p>Talking too much? Say <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">"Shorter, please."</span></p>
        </div>

        @if ($day === 4)
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Afterwards, save the fixes you want to remember in My Words.</p>
        @endif

        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
            First time?
            <a href="{{ route('pi.setup') }}" wire:navigate class="font-semibold underline">How voice practice works</a>
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

    <section class="card space-y-3 p-4" aria-label="Feedback on your chat">
        <label for="pasted-chat" class="block text-sm font-semibold text-ink dark:text-ink-dark">Feedback on your chat</label>
        <textarea
            id="pasted-chat"
            wire:model="pastedChat"
            rows="3"
            placeholder="Copy the chat text from Pi and paste it here"
            class="w-full rounded-xl border border-line bg-transparent p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
        ></textarea>
        @error('pastedChat')
            <p class="text-xs text-danger-ink">{{ $message }}</p>
        @enderror
        <button
            type="button"
            wire:click="checkPastedChat"
            wire:loading.attr="disabled"
            wire:target="checkPastedChat"
            class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-60 dark:bg-accent-dark"
        >
            <span wire:loading.remove wire:target="checkPastedChat">@svg('heroicon-o-sparkles', 'h-4 w-4')</span>
            <span wire:loading wire:target="checkPastedChat">@svg('heroicon-o-sparkles', 'h-4 w-4 animate-pulse')</span>
            <span class="font-fa">بررسی</span>
        </button>

        @if ($feedback || $feedbackError)
            <div wire:loading.class="opacity-60" wire:target="checkPastedChat" class="border-t border-line pt-3 dark:border-line-dark">
                <x-conversation-feedback :feedback="$feedback" :error="$feedbackError" action="checkPastedChat" />
            </div>
        @endif
    </section>
</div>
