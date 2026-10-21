<?php

use App\Models\PairPracticeSession;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public PairPracticeSession $session;

    /** Waiting room: the video-call link the starter pastes. */
    public string $meetLink = '';

    /** @var list<string> Speaking: target words the asker heard. */
    public array $heardWords = [];

    public function mount(PairPracticeSession $session): void
    {
        abort_unless($session->isAccessibleBy(auth()->user()), 403);

        $this->session = $session;
    }

    public function join(): void
    {
        $this->authorizePlayer();
        $this->withLockedSession(fn (PairPracticeSession $session) => $session->join(auth()->user()));
    }

    /**
     * Shares a video-call link (Google Meet or any https call link) with
     * the friend, for practising apart. A link pasted without its scheme
     * ("meet.google.com/abc-defg-hij") gets https:// in front.
     */
    public function shareLink(): void
    {
        $this->authorizePlayer();

        $this->meetLink = trim($this->meetLink);

        if ($this->meetLink !== '' && ! preg_match('#^[a-z][a-z0-9+.-]*:#i', $this->meetLink)) {
            $this->meetLink = 'https://'.$this->meetLink;
        }

        $this->validate(['meetLink' => ['required', 'url:https', 'max:255']]);

        $this->session->update(['meet_link' => $this->meetLink]);
        $this->meetLink = '';
    }

    /**
     * The judge scores a word or grammar card. $card is the number of cards
     * answered when the judge saw it, so a double tap or a card that has
     * already moved on is ignored.
     */
    public function judge(bool $correct, int $card): void
    {
        $this->authorizePlayer();

        $this->withLockedSession(function (PairPracticeSession $session) use ($correct, $card) {
            if ($this->canScore($session, $card) && in_array($session->stage, [PairPracticeSession::STAGE_VOCABULARY, PairPracticeSession::STAGE_GRAMMAR], true)) {
                $session->record($correct ? 1 : 0, $correct);
                $this->heardWords = [];
            }
        });
    }

    /**
     * Speaking: one point for every target word the asker heard, up to
     * SPEAKING_MAX_POINTS.
     */
    public function nextQuestion(int $card): void
    {
        $this->authorizePlayer();

        $this->withLockedSession(function (PairPracticeSession $session) use ($card) {
            if ($this->canScore($session, $card) && $session->stage === PairPracticeSession::STAGE_SPEAKING) {
                $words = $session->currentCard()['words'] ?? [];
                $session->record(min(PairPracticeSession::SPEAKING_MAX_POINTS, count(array_intersect($words, $this->heardWords))), null);
                $this->heardWords = [];
            }
        });
    }

    /**
     * Called by the timer on both phones. A few seconds of slack for clock
     * drift, but an early call never skips a stage, and only the stage the
     * timer belonged to can be closed.
     */
    public function timeUp(string $stage): void
    {
        $this->authorizePlayer();

        $this->withLockedSession(function (PairPracticeSession $session) use ($stage) {
            if ($session->stage === $stage && $session->stage_ends_at && now()->addSeconds(3)->greaterThanOrEqualTo($session->stage_ends_at)) {
                $session->advanceStage();
                $this->heardWords = [];
            }
        });
    }

    /**
     * Ends the round now — or, before the friend joined, calls the invite
     * off without leaving an empty round in the pair's history.
     */
    public function end(): void
    {
        $this->authorizePlayer();

        if ($this->session->isWaiting()) {
            $this->session->delete();
            $this->redirectRoute('pair-practice.index', navigate: true);

            return;
        }

        $this->withLockedSession(fn (PairPracticeSession $session) => $session->finish());
    }

    /**
     * Only the current judge scores, and only the card they were shown.
     */
    private function canScore(PairPracticeSession $session, int $card): bool
    {
        return $session->judge()->is(auth()->user()) && count($session->results ?? []) === $card;
    }

    /**
     * Both phones act on one round, so every change re-reads it under a row
     * lock first.
     */
    private function withLockedSession(callable $callback): void
    {
        DB::transaction(function () use ($callback) {
            $this->session = PairPracticeSession::lockForUpdate()->findOrFail($this->session->id);
            $callback($this->session);
        });
    }

    private function authorizePlayer(): void
    {
        abort_unless($this->session->isAccessibleBy(auth()->user()), 403);
    }
};
?>

@php
    $me = auth()->user();
    $stage = $session->stage;
    $stageLabels = [
        PairPracticeSession::STAGE_VOCABULARY => 'Words',
        PairPracticeSession::STAGE_GRAMMAR => 'Grammar',
        PairPracticeSession::STAGE_SPEAKING => 'Speaking',
    ];
@endphp

<div class="mx-auto max-w-xl space-y-4 p-4 sm:p-6" @unless ($session->isFinished()) wire:poll.2s @endunless>
    @if ($session->isFinished())
        @php [$first, $second] = $session->players(); @endphp
        <header class="text-center">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $session->mission_code }} · Day {{ $session->day_number }}</p>
            <h1 class="mt-1 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Well done, both of you!</h1>
        </header>

        <div class="grid grid-cols-2 gap-3">
            @foreach ([$first, $second] as $player)
                <div wire:key="score-{{ $player->id }}" class="card space-y-2 p-4 text-center">
                    <x-user-avatar :user="$player" class="mx-auto h-10 w-10 text-sm" />
                    <p class="truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $player->name }}</p>
                    <p class="font-display text-3xl font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $session->scoreFor($player) }}</p>
                </div>
            @endforeach
        </div>

        @foreach ([$first, $second] as $player)
            @php $missed = $session->missedWordsFor($player); @endphp
            @if ($missed !== [])
                <div wire:key="missed-{{ $player->id }}" class="card-sunken p-4">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $player->name }} · added to My Words</p>
                    <p class="mt-1 text-sm text-ink dark:text-ink-dark">{{ collect($missed)->pluck('answer')->join(', ') }}</p>
                </div>
            @endif
        @endforeach

        <a href="{{ route('pair-practice.index') }}" wire:navigate class="block w-full rounded-full bg-accent px-4 py-3 text-center text-sm font-semibold text-white dark:bg-accent-dark">Done</a>
    @elseif ($session->isWaiting())
        @php
            $starter = $session->startedBy;
            $friend = $session->partnerFor($me);
            $isStarter = $starter->is($me);
        @endphp

        <a href="{{ route('pair-practice.index') }}" wire:navigate class="inline-flex h-9 w-9 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark" title="Back">@svg('heroicon-o-chevron-left', 'h-5 w-5')</a>

        @if ($session->isExpired())
            <div class="card p-6 text-center">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">This invite has expired.</p>
                <a href="{{ route('pair-practice.index') }}" wire:navigate class="mt-3 inline-block text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Start a new one ›</a>
            </div>
        @else
            <div class="card space-y-4 p-5 text-center">
                <x-user-avatar :user="$isStarter ? $friend : $starter" class="mx-auto h-14 w-14 text-base" />

                @if ($isStarter)
                    <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">Waiting for {{ $friend->name }}…</p>
                @else
                    <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $starter->name }} wants to practise</p>
                @endif

                @if ($session->meet_link)
                    <a href="{{ $session->meet_link }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                        @svg('heroicon-o-video-camera', 'h-4 w-4') Open the call
                    </a>
                @endif

                @unless ($isStarter)
                    <button type="button" wire:click="join" wire:loading.attr="disabled" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Join</button>
                @endunless
            </div>

            @if ($isStarter && ! $session->meet_link)
                <div class="card-sunken space-y-3 p-4">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">Not together? Make a call and share its link.</p>
                    <a href="https://meet.google.com/new" target="_blank" rel="noopener noreferrer" class="flex items-center justify-center gap-1.5 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark">
                        @svg('heroicon-o-video-camera', 'h-4 w-4') Open Google Meet
                    </a>
                    <form wire:submit="shareLink" class="flex gap-2">
                        <input type="text" inputmode="url" autocapitalize="off" autocorrect="off" spellcheck="false" wire:model="meetLink" placeholder="Paste the call link" class="min-w-0 flex-1 rounded-full border border-line bg-surface px-4 py-2 text-sm text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">
                        <button type="submit" class="shrink-0 cursor-pointer rounded-full bg-accent px-4 py-2 text-sm font-semibold text-white dark:bg-accent-dark">Share</button>
                    </form>
                    @error('meetLink') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            @endif

            <button type="button" wire:click="end" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">Cancel</button>
        @endif
    @else
        @php
            $card = $session->currentCard();
            $speaker = $session->speaker();
            $judge = $session->judge();
            $isJudge = $judge->is($me);
            $answered = count($session->results ?? []);
            $lastResult = collect($session->results ?? [])->last();
            $lastCard = $lastResult && $lastResult['stage'] === $stage && $lastResult['correct'] !== null
                ? ($session->deck[$stage][$lastResult['card']] ?? null)
                : null;
        @endphp

        <header class="flex items-center gap-3">
            <a href="{{ route('pair-practice.index') }}" wire:navigate class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark" title="Back">@svg('heroicon-o-chevron-left', 'h-5 w-5')</a>
            <p class="flex-1 text-sm font-semibold text-ink dark:text-ink-dark">
                {{ $stageLabels[$stage] ?? '' }}
                <span class="text-ink-faint dark:text-ink-faint-dark">· {{ min($session->positionInStage() + 1, $session->cardsInStage()) }}/{{ $session->cardsInStage() }}</span>
            </p>
            @if ($session->meet_link)
                <a href="{{ $session->meet_link }}" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark" title="Open the call">@svg('heroicon-o-video-camera', 'h-5 w-5')</a>
            @endif
            <span
                wire:key="timer-{{ $stage }}"
                x-data="{
                    endsAt: {{ $session->stage_ends_at?->getTimestampMs() ?? 0 }},
                    left: 0,
                    fired: false,
                    timer: null,
                    init() {
                        this.tick();
                        this.timer = setInterval(() => this.tick(), 1000);
                    },
                    destroy() {
                        clearInterval(this.timer);
                    },
                    tick() {
                        this.left = Math.max(0, Math.round((this.endsAt - Date.now()) / 1000));
                        if (this.left === 0 && ! this.fired) { this.fired = true; this.$wire.timeUp(@js($stage)); }
                    },
                }"
                x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')"
                class="rounded-full bg-surface-sunken px-3 py-1 font-mono text-sm font-semibold text-ink tabular-nums dark:bg-surface-sunken-dark dark:text-ink-dark"
            ></span>
        </header>

        @if ($card)
            <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">
                @if ($isJudge)
                    ⚖️ You {{ $stage === PairPracticeSession::STAGE_SPEAKING ? 'ask' : 'check' }} · <span class="font-semibold text-ink dark:text-ink-dark">{{ $speaker->name }}</span> answers
                @else
                    🎤 You answer · <span class="font-semibold text-ink dark:text-ink-dark">{{ $judge->name }}</span> {{ $stage === PairPracticeSession::STAGE_SPEAKING ? 'asks' : 'checks' }}
                @endif
            </p>

            <div wire:key="card-{{ $answered }}" class="card space-y-4 p-5">
                @if ($stage === PairPracticeSession::STAGE_VOCABULARY)
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it in English</p>
                    <p dir="rtl" class="text-center font-display text-2xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                @elseif ($stage === PairPracticeSession::STAGE_GRAMMAR)
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it correctly</p>
                    <p class="text-center text-lg font-semibold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                @else
                    <p class="text-center font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['question'] }}</p>
                @endif

                @if ($isJudge)
                    <div class="rounded-xl bg-surface-sunken p-3 text-center dark:bg-surface-sunken-dark">
                        @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Sample answer</p>
                        @endif
                        <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $card['answer'] }}</p>
                        @if ($stage === PairPracticeSession::STAGE_VOCABULARY && filled($card['example'] ?? null))
                            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $card['example'] }}</p>
                        @endif
                    </div>

                    @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                        <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Too short? Ask: “Why? Give an example.”</p>

                        @if (($card['words'] ?? []) !== [])
                            <div>
                                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Tap the words you hear</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($card['words'] as $word)
                                        <label wire:key="word-{{ $loop->index }}" class="cursor-pointer">
                                            <input type="checkbox" wire:model="heardWords" value="{{ $word }}" class="peer sr-only">
                                            <span class="inline-block rounded-full border border-line px-3 py-1 text-sm text-ink-soft peer-checked:border-accent peer-checked:bg-accent-soft peer-checked:text-accent-ink dark:border-line-dark dark:text-ink-soft-dark dark:peer-checked:bg-accent-soft-dark dark:peer-checked:text-accent-ink-dark">{{ $word }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                @endif
            </div>

            @if ($isJudge && $stage === PairPracticeSession::STAGE_SPEAKING)
                <button type="button" wire:click="nextQuestion({{ $answered }})" wire:loading.attr="disabled" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next · swap</button>
            @elseif ($isJudge)
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="judge(false, {{ $answered }})" wire:loading.attr="disabled" class="cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">✗ Not yet</button>
                    <button type="button" wire:click="judge(true, {{ $answered }})" wire:loading.attr="disabled" class="cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">✓ Right</button>
                </div>
            @endif

            @if ($lastCard)
                <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Last: <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $lastCard['answer'] }}</span> {{ $lastResult['correct'] ? '✓' : '✗' }}</p>
            @endif

            <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">{{ $card['source'] ?? '' }}</p>
        @endif

        <button type="button" wire:click="end" wire:confirm="End this round now?" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">End round</button>
    @endif
</div>
