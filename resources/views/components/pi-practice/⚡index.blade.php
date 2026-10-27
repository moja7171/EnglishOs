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

    /**
     * Picks the prompt's random angles (see PiPrompts::dayTask()), so the
     * prompt stays the same while the page is open and changes on the
     * next visit.
     */
    #[Locked]
    public int $promptSeed = 0;

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
        $this->promptSeed = random_int(1, PHP_INT_MAX);

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
                .'words and "Q:" is the AI\'s question, or a whole chat. Speaker names may be missing — the AI\'s turns are usually longer and '
                .'ask questions or give corrections; judge ONLY the learner\'s own turns. Ignore filler words.',
            source: 'pi',
            spoken: true,
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
     * @return array{title: string, goal: string, plan: list<string>, prompt: string, feedback: string}
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
        $copiedKey = 'eos-pi-copied:'.$missionCode.':'.$day;
        $openPi = 'if (/Android/i.test(navigator.userAgent)) { window.location.href = '.App\Services\PiPrompts::piAndroidIntentJs().' }';
        $notCounted = ($feedback['practice_done'] ?? null) === false && ! $this->isPracticed;
        $normalized = $feedback ? App\Services\LearnerFeedback::normalize($feedback) : null;
        $firstIssue = $normalized ? collect($normalized['sentences'])->first(fn ($sentence) => $sentence['status'] !== 'ok') : null;
        $stepLabels = ['Talk', 'Paste', 'Feedback'];
    @endphp

    {{-- One card that changes with the learner's step: 1 copy the prompt
         and talk in Pi, 2 back from Pi: paste Pi's "transcript" answer, 3
         the feedback — the only way the day gets its tick (see
         checkPastedChat()). The page remembers the copy tap, so coming
         back from Pi lands on step 2 even after a reload. --}}
    <div
        x-data="{
            copied: false,
            manual: false,
            fullPrompt: false,
            showPrompt: false,
            showAll: false,
            init() {
                try { this.copied = localStorage.getItem({{ Illuminate\Support\Js::from($copiedKey) }}) === '1' } catch (e) {}
            },
            copyAndOpen() {
                navigator.clipboard?.writeText($refs.prompt.value);
                this.copied = true;
                try { localStorage.setItem({{ Illuminate\Support\Js::from($copiedKey) }}, '1') } catch (e) {}
                {{ $openPi }}
            },
            async pasteAndCheck() {
                try {
                    const text = await navigator.clipboard.readText();
                    if (text && text.trim().length >= 40) {
                        $wire.pastedChat = text;
                        await $wire.checkPastedChat();
                        return;
                    }
                } catch (e) {}
                this.manual = true;
            },
            get step() { return $wire.feedback ? 3 : (this.copied ? 2 : 1) },
        }"
        class="space-y-4"
    >
        <textarea x-ref="prompt" readonly hidden aria-hidden="true">{{ $this->task['prompt'] }}</textarea>

        @unless ($this->isAhead)
            <ol class="flex items-center gap-1.5 px-1 text-xs font-extrabold text-ink-faint dark:text-ink-faint-dark" aria-label="Steps">
                @foreach ($stepLabels as $index => $label)
                    @php $number = $index + 1; @endphp
                    <li
                        class="flex items-center gap-1.5"
                        :class="step > {{ $number }} ? 'text-success dark:text-success-dark' : (step === {{ $number }} ? 'text-accent-ink dark:text-accent-ink-dark' : '')"
                        x-bind:aria-current="step === {{ $number }} ? 'step' : null"
                    >
                        <span
                            class="inline-flex h-5 w-5 items-center justify-center rounded-full border-2 border-line text-[10px] dark:border-line-dark"
                            :class="step > {{ $number }} ? 'border-success bg-success text-white dark:border-success-dark dark:bg-success-dark' : (step === {{ $number }} ? 'border-accent bg-accent text-white dark:border-accent-dark dark:bg-accent-dark' : '')"
                            x-text="step > {{ $number }} ? '✓' : '{{ $number }}'"
                        >{{ $number }}</span>
                        {{ $label }}
                    </li>
                    @if (! $loop->last)
                        <li aria-hidden="true" class="h-0.5 flex-1 rounded-full bg-line dark:bg-line-dark" :class="step > {{ $number }} ? 'bg-success dark:bg-success-dark' : ''"></li>
                    @endif
                @endforeach
            </ol>
        @endunless

        {{-- 1. Talk --}}
        <section wire:key="pi-step-talk" x-show="step === 1" @unless ($this->isAhead) x-cloak @endunless class="card flex flex-col items-center gap-3 p-5 text-center" aria-label="Talk with Pi">
            <span class="inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-linear-to-br from-[#1fa98a] to-[#0e5c4b] text-xl font-extrabold text-white" aria-hidden="true">Pi</span>
            <h2 class="text-lg font-extrabold text-ink dark:text-ink-dark">Talk with Pi · {{ App\Services\PiPractice::MINUTES }} min</h2>
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">Pi asks, you answer. Then you ask Pi.</p>
            @unless ($this->isAhead)
                <button type="button" x-on:click="copyAndOpen()" class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-sm transition-colors hover:opacity-90 dark:bg-accent-dark">
                    @svg('heroicon-s-microphone', 'h-5 w-5') Copy &amp; open Pi
                </button>
            @endunless
            <button type="button" x-on:click="showPrompt = true" class="cursor-pointer text-sm font-extrabold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">See prompt</button>
        </section>

        @unless ($this->isAhead)
            {{-- 2. Back from Pi: one button reads Pi's answer from the
                 clipboard; the box only shows when the phone won't allow it. --}}
            <section wire:key="pi-step-paste" x-show="step === 2" x-cloak class="card flex flex-col items-center gap-3 p-5 text-center" aria-label="Paste">
                <span class="text-4xl" aria-hidden="true">📋</span>
                <h2 class="text-lg font-extrabold text-ink dark:text-ink-dark">Back from Pi?</h2>
                <p class="w-full rounded-2xl bg-surface-sunken px-3 py-2.5 text-sm font-bold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">In Pi, type <span class="text-accent-ink dark:text-accent-ink-dark">transcript</span><br>then copy Pi's answer</p>

                <div x-show="manual" x-cloak class="w-full text-left">
                    <label for="pasted-chat" class="sr-only">Pi's answer</label>
                    <textarea id="pasted-chat" wire:model="pastedChat" rows="4" placeholder="Paste Pi's answer here" class="w-full rounded-2xl border border-line bg-transparent p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark"></textarea>
                </div>
                @error('pastedChat')
                    <p class="text-xs text-danger-ink" x-init="manual = true">{{ $message }}</p>
                @enderror
                @if ($feedbackError && ! $feedback)
                    <p class="text-sm text-danger-ink">{{ $feedbackError }}</p>
                @endif

                <button
                    type="button"
                    x-on:click="manual ? $wire.checkPastedChat() : pasteAndCheck()"
                    wire:loading.attr="disabled"
                    wire:target="checkPastedChat"
                    class="inline-flex w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-sm transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-60 dark:bg-accent-dark"
                >
                    <span wire:loading.remove wire:target="checkPastedChat" x-text="manual ? 'Check' : 'Paste & check'">Paste &amp; check</span>
                    <span wire:loading wire:target="checkPastedChat">Checking…</span>
                </button>
                <button type="button" x-on:click="copyAndOpen()" class="cursor-pointer text-sm font-extrabold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">Open Pi again</button>
            </section>

            {{-- 3. Feedback: the summary and the first thing to fix, the
                 whole feedback one tap away. --}}
            @if ($normalized)
                <section wire:key="pi-step-feedback" class="space-y-3" aria-label="Feedback">
                    <div class="card space-y-3 p-4" dir="rtl">
                        <div class="flex items-center justify-between gap-2" dir="ltr">
                            <h2 class="text-base font-extrabold text-ink dark:text-ink-dark">Your feedback</h2>
                            <button type="button" x-on:click="showAll = true" class="cursor-pointer text-sm font-extrabold text-accent-ink dark:text-accent-ink-dark">See all ›</button>
                        </div>
                        @if ($normalized['summary'])
                            <p class="font-fa text-sm font-semibold text-ink dark:text-ink-dark">{{ $normalized['summary'] }}</p>
                        @endif
                        @if ($firstIssue)
                            <div class="rounded-2xl border border-line p-3 dark:border-line-dark">
                                <x-learner-feedback-sentence :sentence="$firstIssue" />
                            </div>
                        @endif
                    </div>
                    @if ($notCounted)
                        <p class="text-center text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">{{ $this->isToday ? "Not counted — this doesn't look like today's practice." : "Not counted — this doesn't look like this day's practice." }}</p>
                    @endif
                    <div class="flex items-center justify-center gap-4 text-xs font-extrabold text-ink-faint dark:text-ink-faint-dark">
                        <span>Saved to your Notebook</span>
                        <button type="button" wire:click="dismissFeedback" class="cursor-pointer underline hover:text-ink dark:hover:text-ink-dark">Paste again</button>
                    </div>

                    {{-- The whole feedback, full screen like a lesson's. --}}
                    <div
                        x-show="showAll"
                        x-cloak
                        x-back-close="{ open: showAll, close: () => showAll = false }"
                        x-on:keydown.escape.window="showAll = false"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Your feedback"
                        class="fixed inset-0 z-50 overflow-y-auto bg-ground dark:bg-ground-dark"
                    >
                        <div class="mx-auto flex max-w-lg flex-col gap-4 px-5 pt-[max(0.75rem,env(safe-area-inset-top))] pb-10">
                            <x-lesson-header close-click="showAll = false" :progress="1" />
                            <x-step-intro kind="Feedback" />
                            <x-learner-feedback :feedback="$feedback" fold-techniques />
                        </div>
                    </div>
                </section>
            @endif
        @endunless

        {{-- What Pi will do: the day's plan, the full prompt one tap further. --}}
        <x-bottom-sheet show="showPrompt" label="What Pi will do">
            <x-slot:title>What Pi will do</x-slot:title>
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
