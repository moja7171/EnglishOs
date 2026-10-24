<?php

use App\Livewire\Concerns\GivesConversationFeedback;
use App\Models\Mission;
use App\Models\PiChatFeedback;
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
     * Locked: these decide which day's practice is shown AND which day the
     * practice tick is filed under, so the browser must never be able
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

        $saved = $this->savedChatFeedback();

        if ($saved !== null && ! $saved->isClosed()) {
            $this->pastedChat = $saved->chat;
            $this->feedback = $saved->feedback;
        }
    }

    /**
     * Pi sends the learner's own words in one "My words:" message (see
     * PiPrompts), which the learner copies and pastes here; a whole copied
     * chat still works. Speaker names may or may not come along, so the
     * AI is told how to tell the two sides apart, and it judges against
     * the day's own goal (see PiPrompts::dayTask()). A good result is
     * kept for the day on screen (see PiChatFeedback).
     *
     * This is also the only way the practice gets ticked: there is no
     * "I practiced" button, the AI says whether the chat really did the
     * day's plan, and only then is today counted toward the streak (see
     * User::activeDates()), filed under the day on screen. The day is
     * re-checked here, not trusted from mount(): a day ahead is refused
     * before any AI is asked or anything is kept.
     */
    public function checkPastedChat(): void
    {
        if ($this->isAhead) {
            return;
        }

        $this->validate(['pastedChat' => ['required', 'string', 'min:40', 'max:12000']], attributes: ['pastedChat' => 'chat']);

        $this->requestConversationFeedback(
            $this->pastedChat,
            situation: 'a voice conversation the learner had with an AI speaking partner (such as Pi), copied from '
                .'the app and pasted in. It may be a "My words:" list where each "Me:" line is the learner\'s exact '
                .'words and "Q:" is the AI\'s question (or "Line:" the line to repeat), or a whole chat. Speaker names may be missing — the AI\'s turns are usually longer and '
                .'ask questions or give corrections; judge ONLY the learner\'s own turns. Ignore filler words.',
            source: 'pi',
            spoken: ! $this->task['isPronunciationDrill'],
            extraGuidance: $this->task['feedback'],
            practiceTask: implode('; ', $this->task['plan']),
        );

        if ($this->feedback === null) {
            return;
        }

        // A day once done stays done, even if a later paste isn't the practice.
        $wasDone = $this->savedChatFeedback()?->isPracticeDone() ?? false;

        auth()->user()->piChatFeedback()->updateOrCreate(
            ['mission_code' => $this->missionCode, 'day_number' => $this->day],
            ['chat' => $this->pastedChat, 'feedback' => $wasDone ? [...$this->feedback, 'practice_done' => true] : $this->feedback],
        );

        if (($this->feedback['practice_done'] ?? false) === true) {
            auth()->user()->recordPiPracticeToday($this->missionCode, $this->day);

            unset($this->practicedToday);
        }

        unset($this->practicedDaysByMission, $this->practicedDays, $this->isPracticed, $this->missionDays);
    }

    /**
     * Closing the card forgets the saved chat and feedback of this day,
     * but a done practice keeps its tick (see PiChatFeedback::close()).
     */
    public function dismissFeedback(): void
    {
        $this->savedChatFeedback()?->close();

        $this->pastedChat = '';
        $this->feedback = null;
        $this->feedbackError = null;
    }

    /**
     * The last chat pasted for the day on screen, with its feedback.
     */
    private function savedChatFeedback(): ?PiChatFeedback
    {
        return auth()->user()->piChatFeedback()
            ->where('mission_code', $this->missionCode)
            ->where('day_number', $this->day)
            ->first();
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
     * @return array{title: string, goal: string, plan: list<string>, prompt: string, feedback: string, isPronunciationDrill: bool}
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
     * The tick on the banner: today's own (it is what the streak counts),
     * or, on another day, that day's.
     */
    #[Computed]
    public function isPracticed(): bool
    {
        return $this->isToday ? $this->practicedToday : in_array($this->day, $this->practicedDays, true);
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

<div class="mx-auto max-w-2xl space-y-5 p-6" x-data="{ showInfo: false }">
    {{-- The mission's photo banner, the same as /listening: the way back
         to this day on the mission's page (where Daily talk sits beside
         the day's Lesson and Listening), the four days, and whether
         this day's practice is done — never a button: the feedback below
         ticks it once the AI sees the day's plan really done (see
         checkPastedChat()). --}}
    <x-mission-banner
        :mission-code="$missionCode"
        title="Daily talk"
        icon="heroicon-o-microphone"
        :back-href="app(App\Services\MissionGrid::class)->pageUrl($missionCode, $day)"
        :back-label="$missionCode.' · '.$this->missionTitle.' · Day '.$day"
        :done-label="$this->isPracticed ? 'Practiced' : null"
    >
        <x-slot:actions>
            <button
                type="button"
                x-on:click="showInfo = ! showInfo"
                x-bind:aria-expanded="showInfo"
                aria-controls="pi-practice-info"
                aria-label="How it works"
                class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-white/85 transition-colors hover:bg-white/15 hover:text-white"
            >
                @svg('heroicon-o-information-circle', 'h-6 w-6')
            </button>
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

    {{-- How-to and tips stay behind the ⓘ on the banner, so the page itself
         is just the two cards: talk, then feedback. --}}
    <section id="pi-practice-info" x-show="showInfo" x-cloak x-transition class="card space-y-3 p-4 text-sm text-ink-soft dark:text-ink-soft-dark" aria-label="How it works">
        <p>{{ $this->task['goal'] }}</p>
        <ol class="list-decimal space-y-1.5 ps-5">
            <li>
                Open the voice AI app you like best —
                <a href="https://pi.ai" target="_blank" rel="noopener noreferrer" x-data x-on:click="if (/Android/i.test(navigator.userAgent)) { $event.preventDefault(); window.location.href = {{ App\Services\PiPrompts::piAndroidIntentJs() }} }" class="text-accent-ink underline dark:text-accent-ink-dark">Pi</a>,
                <a href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer" class="text-accent-ink underline dark:text-accent-ink-dark">Gemini Live</a>,
                or any other one you already use.
            </li>
            <li>Turn on voice mode first.</li>
            <li>Copy the prompt, send it, and talk out loud.</li>
        </ol>
        <div class="space-y-1 text-xs text-ink-faint dark:text-ink-faint-dark">
            <p>
                Say one on its own, any time:
                @foreach (PiPrompts::CONTROL_WORDS as $word => $meaning)
                    <span class="text-ink-soft dark:text-ink-soft-dark">{{ $word }}</span> ({{ $meaning }}){{ $loop->last ? '.' : ',' }}
                @endforeach
            </p>
            <p>Talking too much? Say <span class="text-ink-soft dark:text-ink-soft-dark">"Shorter, please."</span></p>
            @if ($day === 4)
                <p>Afterwards, save the fixes you want to remember in My Words.</p>
            @endif
            <p>The last thing of your day, after the mission steps. It is never a step and never blocks anything.</p>
            <p>
                First time?
                <a href="{{ route('pi.setup') }}" wire:navigate class="underline">How Daily talk works</a>
            </p>
        </div>
    </section>

    @php
        $stepNumber = 'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark';
    @endphp

    {{-- 1. The talk itself, outside the app: one big button copies the
         prompt and opens Pi; the prompt and the plan stay folded. --}}
    <section class="card space-y-3 p-4" aria-labelledby="talk-heading">
        <h2 id="talk-heading" class="flex items-center gap-2.5 text-base font-bold text-ink dark:text-ink-dark">
            <span class="{{ $stepNumber }}">1</span> Talk with Pi
        </h2>
        <x-pi-practice-card :task="$this->task" :plan-label="$this->isToday ? 'Today\'s plan' : 'Day '.$day.' plan'" :preview="$this->isAhead" />
    </section>

    @unless ($this->isAhead)
        {{-- 2. Pi's last message pasted back: the feedback, and the only way
             the day gets its tick (see checkPastedChat()). --}}
        <section class="card space-y-3 p-4" aria-labelledby="feedback-heading">
            <h2 id="feedback-heading" class="flex items-center gap-2.5 text-base font-bold text-ink dark:text-ink-dark">
                <span class="{{ $stepNumber }}">2</span>
                <label for="pasted-chat">Get feedback</label>
            </h2>
            <textarea
                id="pasted-chat"
                wire:model="pastedChat"
                rows="3"
                placeholder="Paste Pi's last message here"
                class="w-full rounded-xl border border-line bg-transparent p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            ></textarea>
            @error('pastedChat')
                <p class="text-xs text-danger-ink">{{ $message }}</p>
            @enderror
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <button
                    type="button"
                    wire:click="checkPastedChat"
                    wire:loading.attr="disabled"
                    wire:target="checkPastedChat"
                    class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-60 dark:bg-accent-dark"
                >
                    <span wire:loading.remove wire:target="checkPastedChat">@svg('heroicon-o-sparkles', 'h-4 w-4')</span>
                    <span wire:loading wire:target="checkPastedChat">@svg('heroicon-o-sparkles', 'h-4 w-4 animate-pulse')</span>
                    Feedback
                </button>
                @if (! $this->isPracticed)
                    <span class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ ($feedback['practice_done'] ?? null) === false ? ($this->isToday ? "Not counted — this doesn't look like today's practice." : "Not counted — this doesn't look like this day's practice.") : 'Ticks itself after Feedback.' }}</span>
                @endif
            </div>

            @if ($feedback || $feedbackError)
                <div wire:loading.class="opacity-60" wire:target="checkPastedChat" class="border-t border-line pt-3 dark:border-line-dark">
                    <x-conversation-feedback :feedback="$feedback" :error="$feedbackError" action="checkPastedChat" />
                </div>
            @endif
        </section>
    @endunless
</div>
