<?php

use App\Models\PairPracticeSession;
use App\Notifications\PairPracticeInvite;
use App\Services\PairPractice;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public PairPracticeSession $session;

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
                $heard = array_values(array_intersect($words, $this->heardWords));
                $session->record(min(PairPracticeSession::SPEAKING_MAX_POINTS, count($heard)), null, $heard);
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
     * Before the friend joined: calls the invite off (kept, so the friend's
     * page says so instead of breaking). During a round: ends it for both
     * — too short a round doesn't count.
     */
    public function end(): void
    {
        $this->authorizePlayer();

        $wasWaiting = $this->session->isWaiting();

        $this->withLockedSession(fn (PairPracticeSession $session) => $session->isWaiting()
            ? $session->cancel(auth()->user())
            : $session->endEarly(auth()->user()));

        if ($wasWaiting) {
            $this->redirectRoute('pair-practice.index', navigate: true);
        }
    }

    /**
     * After a round: picks up the pair's next round if one of the two has
     * already started it, or draws a new one from the same days and
     * invites the friend.
     */
    public function anotherRound(): void
    {
        $this->authorizePlayer();

        $me = auth()->user();
        $friend = $this->session->partnerFor($me);

        abort_unless($me->canMessageWith($friend), 403);

        $next = $this->nextRound();

        if (! $next && $next = app(PairPractice::class)->start($me, $friend, $this->session->days)) {
            $friend->notify(new PairPracticeInvite($next, $me));
        }

        if ($next) {
            $this->redirectRoute('pair-practice.show', $next, navigate: true);
        }
    }

    /**
     * A round of the same pair started after this one and still open.
     */
    public function nextRound(): ?PairPracticeSession
    {
        [$first, $second] = $this->session->players();

        return PairPracticeSession::forPair($first, $second)->open()->whereKeyNot($this->session->id)->latest()->first();
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
    $stages = [
        PairPracticeSession::STAGE_VOCABULARY => ['label' => 'Words', 'task' => 'Say it in English'],
        PairPracticeSession::STAGE_GRAMMAR => ['label' => 'Grammar', 'task' => 'Say it correctly'],
        PairPracticeSession::STAGE_SPEAKING => ['label' => 'Speaking', 'task' => 'Ask and answer'],
    ];
@endphp

<div class="mx-auto max-w-xl space-y-4 p-4 sm:p-6" @unless ($session->isCancelled()) wire:poll.{{ $session->isFinished() ? '4s' : '2s' }} @endunless>
    @if ($session->isCancelled())
        @php
            $endedBy = $session->endedBy;
            $who = $endedBy?->is($me) ? 'You' : ($endedBy->name ?? 'Your friend');
        @endphp
        <div class="card space-y-2 p-6 text-center">
            @if ($session->joined_at === null)
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $who }} cancelled the invite.</p>
            @else
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $who }} ended the round.</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Too short to count — try a full round next time.</p>
            @endif
            <a href="{{ route('pair-practice.index') }}" wire:navigate class="inline-block pt-1 text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Back ›</a>
        </div>
    @elseif ($session->isFinished())
        @php
            [$first, $second] = $session->players();
            $friend = $session->partnerFor($me);
            $myWords = collect($session->missedWordsFor($me))->pluck('answer')->concat($session->unusedWordsFor($me))->unique()->values();
            $mySentences = $session->missedSentencesFor($me);
            $next = $this->nextRound();
            $streak = app(PairPractice::class)->pairStreak($me, $friend);
        @endphp

        <div class="card space-y-5 p-6 text-center">
            <div>
                <p class="animate-trophy-pop text-4xl" aria-hidden="true">🎉</p>
                <h1 class="mt-2 font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Well done, both of you!</h1>
                <p class="mt-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">
                    {{ $session->daysLabel() }}@if ($streak > 0) · 🔥 {{ $streak }}@endif
                </p>
                @if ($session->endedBy)
                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $session->endedBy->is($me) ? 'You' : $session->endedBy->name }} ended the round.</p>
                @endif
            </div>

            <div class="flex items-center justify-center gap-6">
                @foreach ([$first, $second] as $player)
                    <div wire:key="score-{{ $player->id }}" class="min-w-0">
                        <p class="font-display text-4xl font-extrabold text-accent-ink tabular-nums dark:text-accent-ink-dark">{{ $session->scoreFor($player) }}</p>
                        <p class="truncate text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $player->is($me) ? 'You' : $player->name }}</p>
                    </div>
                    @if ($loop->first)
                        <span class="text-ink-faint dark:text-ink-faint-dark" aria-hidden="true">·</span>
                    @endif
                @endforeach
            </div>

            @if ($myWords->isNotEmpty() || $mySentences !== [])
                <div class="space-y-2 rounded-xl bg-surface-sunken p-4 text-start dark:bg-surface-sunken-dark">
                    <p class="text-xs font-semibold text-ink-soft dark:text-ink-soft-dark">For your review (saved)</p>
                    @if ($myWords->isNotEmpty())
                        <p class="text-sm text-ink dark:text-ink-dark"><span class="text-ink-faint dark:text-ink-faint-dark">Words ·</span> {{ $myWords->join(', ') }}</p>
                    @endif
                    @foreach ($mySentences as $card)
                        <p wire:key="sentence-{{ $loop->index }}" class="text-sm text-ink dark:text-ink-dark"><span class="text-ink-faint dark:text-ink-faint-dark">Grammar ·</span> {{ $card['answer'] }}</p>
                    @endforeach
                </div>
            @endif

            <div class="space-y-2">
                <button type="button" wire:click="anotherRound" wire:loading.attr="disabled" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">
                    {{ $next && $next->started_by_id !== $me->id ? 'Join '.$friend->name.'’s next round' : 'Another round' }}
                </button>
                <a href="{{ route('pair-practice.index') }}" wire:navigate class="block py-1 text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Done</a>
            </div>
        </div>
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
            <div class="card space-y-4 p-6 text-center">
                <x-user-avatar :user="$isStarter ? $friend : $starter" class="mx-auto h-16 w-16 text-lg" />

                <div>
                    @if ($isStarter)
                        <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">Waiting for {{ $friend->name }}…</p>
                    @else
                        <p class="font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $starter->name }} wants to practise</p>
                    @endif
                    <p class="mt-1 text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $session->daysLabel() }}{{ $session->days === null ? ' + review' : '' }} · 12 min</p>
                </div>

                @unless ($isStarter)
                    <button type="button" wire:click="join" wire:loading.attr="disabled" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Join</button>
                @endunless

                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Not together? Call on any app.</p>
            </div>

            <button type="button" wire:click="end" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">Cancel</button>
        @endif
    @else
        @php
            $card = $session->currentCard();
            $speaker = $session->speaker();
            $judge = $session->judge();
            $isJudge = $judge->is($me);
            $isTalk = ($card['type'] ?? null) === 'talk';
            $answered = count($session->results ?? []);
            $stageKeys = array_keys($stages);
            $stageIndex = array_search($stage, $stageKeys, true);
            $stageProgress = $session->cardsInStage() > 0 ? $session->positionInStage() / $session->cardsInStage() : 0;
            $stageElapsed = $session->stage_ends_at ? PairPracticeSession::STAGE_SECONDS[$stage] - now()->diffInSeconds($session->stage_ends_at, false) : 0;
            $lastResult = collect($session->results ?? [])->last();
            $flashCard = $lastResult
                && $lastResult['correct'] !== null
                && $lastResult['speaker_id'] === $me->id
                && now()->getTimestamp() - ($lastResult['at'] ?? 0) <= 5
                    ? ($session->deck[$lastResult['stage']][$lastResult['card']] ?? null)
                    : null;
        @endphp

        <header class="space-y-2">
            <div class="flex items-center gap-3">
                <a href="{{ route('pair-practice.index') }}" wire:navigate class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark" title="Back">@svg('heroicon-o-chevron-left', 'h-5 w-5')</a>
                <div class="grid flex-1 grid-cols-3 gap-1.5" aria-label="Progress">
                    @foreach ($stages as $key => $info)
                        @php $index = array_search($key, $stageKeys, true); @endphp
                        <div wire:key="bar-{{ $key }}">
                            <div class="h-1.5 overflow-hidden rounded-full bg-surface-sunken dark:bg-surface-sunken-dark">
                                <div class="h-full rounded-full bg-accent transition-all duration-500 dark:bg-accent-dark" style="width: {{ $index < $stageIndex ? 100 : ($index === $stageIndex ? round($stageProgress * 100) : 0) }}%"></div>
                            </div>
                            <p class="mt-1 text-[11px] font-semibold {{ $index === $stageIndex ? 'text-ink dark:text-ink-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">{{ $info['label'] }}</p>
                        </div>
                    @endforeach
                </div>
                <span
                    wire:key="timer-{{ $stage }}"
                    x-data="{
                        endsAt: {{ $session->stage_ends_at?->getTimestampMs() ?? 0 }},
                        left: 0,
                        fired: false,
                        warned: false,
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
                            if (this.left > 0 && this.left <= 15 && ! this.warned) { this.warned = true; navigator.vibrate?.(200); }
                            if (this.left === 0 && ! this.fired) { this.fired = true; this.$wire.timeUp(@js($stage)); }
                        },
                    }"
                    x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')"
                    x-bind:class="left <= 15 ? 'bg-warning-soft text-warning-ink animate-pulse' : 'bg-surface-sunken text-ink dark:bg-surface-sunken-dark dark:text-ink-dark'"
                    class="shrink-0 rounded-full px-3 py-1 font-mono text-sm font-semibold tabular-nums transition-colors"
                ></span>
            </div>
        </header>

        @if ($stageElapsed < 2)
            {{-- A short "Part 2 of 3" card as each stage opens. --}}
            <div
                wire:key="intro-{{ $stage }}"
                x-data="{ show: true }"
                x-init="setTimeout(() => show = false, {{ max(0, (int) ((2 - $stageElapsed) * 1000)) }})"
                x-show="show"
                x-transition.opacity.duration.300ms
                class="fixed inset-0 z-[60] flex items-center justify-center bg-ground/95 p-6 dark:bg-ground-dark/95"
            >
                <div class="text-center">
                    <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Part {{ $stageIndex + 1 }} of 3</p>
                    <p class="animate-badge-pop mt-2 font-display text-3xl font-extrabold text-ink dark:text-ink-dark">{{ $stages[$stage]['label'] }}</p>
                    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $stages[$stage]['task'] }} · {{ intdiv(PairPracticeSession::STAGE_SECONDS[$stage], 60) }} min</p>
                </div>
            </div>
        @endif

        @if ($flashCard)
            {{-- The speaker sees how the judge scored their answer. --}}
            <div
                wire:key="flash-{{ $answered }}"
                x-data="{ show: true }"
                x-init="setTimeout(() => show = false, 2200)"
                x-show="show"
                x-transition.opacity.duration.300ms
                x-on:click="show = false"
                class="fixed inset-x-4 top-1/3 z-[55] mx-auto max-w-md rounded-2xl p-5 text-center shadow-2xl {{ $lastResult['correct'] ? 'bg-success-soft dark:bg-success-soft-dark' : 'bg-warning-soft dark:bg-surface-dark' }}"
            >
                @if ($lastResult['correct'])
                    <p class="text-3xl font-extrabold text-success dark:text-success-dark">✓</p>
                @else
                    <p class="text-sm font-semibold text-warning-ink dark:text-ink-soft-dark">Not yet — it's</p>
                @endif
                <p class="mt-1 font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $flashCard['answer'] }}</p>
            </div>
        @endif

        @if ($card)
            <div wire:key="card-{{ $answered }}" class="animate-review-card-in overflow-hidden rounded-2xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark">
                @if ($isJudge)
                    <div class="flex items-center gap-2 bg-surface-sunken px-5 py-3 dark:bg-surface-sunken-dark">
                        <span class="text-xl" aria-hidden="true">👂</span>
                        <p class="text-sm font-bold text-ink dark:text-ink-dark">LISTEN <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">— {{ $speaker->name }} answers</span></p>
                    </div>
                @else
                    <div class="flex items-center gap-2 bg-accent px-5 py-3 text-white dark:bg-accent-dark">
                        <span class="text-xl" aria-hidden="true">🎤</span>
                        <p class="text-sm font-bold">YOUR TURN <span class="font-semibold opacity-90">— {{ $isTalk ? 'talk' : ($stage === PairPracticeSession::STAGE_SPEAKING ? 'answer' : 'say it') }}</span></p>
                    </div>
                @endif

                <div class="space-y-4 p-5">
                    @if ($stage === PairPracticeSession::STAGE_VOCABULARY)
                        <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it in English</p>
                        <p dir="rtl" class="text-center font-display text-3xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                    @elseif ($stage === PairPracticeSession::STAGE_GRAMMAR)
                        <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it correctly</p>
                        <p class="text-center text-lg font-semibold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                    @elseif ($isTalk)
                        <p class="text-center text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $isJudge ? $speaker->name.' talks using these words' : 'Talk for a minute using these words' }}</p>
                        @unless ($isJudge)
                            <div class="flex flex-wrap justify-center gap-2">
                                @foreach ($card['words'] as $word)
                                    <span wire:key="talk-{{ $loop->index }}" class="rounded-full bg-accent-soft px-4 py-1.5 font-display text-lg font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $word }}</span>
                                @endforeach
                            </div>
                        @endunless
                    @else
                        <p class="text-center font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['question'] }}</p>
                    @endif

                    @if ($isJudge)
                        @if (! $isTalk)
                            <div class="rounded-xl bg-success-soft p-3 text-center dark:bg-success-soft-dark">
                                @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                                    <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Sample answer</p>
                                @endif
                                <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $card['answer'] }}</p>
                                @if ($stage === PairPracticeSession::STAGE_VOCABULARY && filled($card['example'] ?? null))
                                    <p class="mt-1 text-xs text-ink-soft dark:text-ink-soft-dark">{{ $card['example'] }}</p>
                                @endif
                            </div>
                        @endif

                        @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                            @unless ($isTalk)
                                <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Too short? Ask: “Why? Give an example.”</p>
                            @endunless

                            @if (($card['words'] ?? []) !== [])
                                <div>
                                    <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">Tap the words you hear</p>
                                    <div class="mt-2 flex flex-wrap justify-center gap-2">
                                        @foreach ($card['words'] as $word)
                                            <label wire:key="word-{{ $loop->index }}" class="cursor-pointer">
                                                <input type="checkbox" wire:model="heardWords" value="{{ $word }}" class="peer sr-only">
                                                <span class="inline-block rounded-full border border-line px-3 py-1.5 text-sm text-ink-soft peer-checked:border-accent peer-checked:bg-accent-soft peer-checked:text-accent-ink dark:border-line-dark dark:text-ink-soft-dark dark:peer-checked:bg-accent-soft-dark dark:peer-checked:text-accent-ink-dark">{{ $word }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                    @endif
                </div>
            </div>

            @if ($isJudge && $stage === PairPracticeSession::STAGE_SPEAKING)
                <button type="button" wire:click="nextQuestion({{ $answered }})" wire:loading.attr="disabled" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next · swap</button>
            @elseif ($isJudge)
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="judge(false, {{ $answered }})" wire:loading.attr="disabled" class="cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">✗ Not yet</button>
                    <button type="button" wire:click="judge(true, {{ $answered }})" wire:loading.attr="disabled" class="cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">✓ Right</button>
                </div>
            @else
                <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">{{ $judge->name }} checks on their phone</p>
            @endif

            <p class="text-center text-[11px] text-ink-faint dark:text-ink-faint-dark">{{ $card['source'] ?? '' }}</p>
        @endif

        <div x-data="{ confirming: false }" x-on:keydown.escape.window="confirming = false">
            <button type="button" x-on:click="confirming = true" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">End round</button>

            <div x-show="confirming" x-cloak x-transition.opacity x-on:click="confirming = false" class="fixed inset-0 z-[60] bg-ink/40"></div>
            <div
                x-show="confirming"
                x-cloak
                x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
                x-transition:enter-start="translate-y-full"
                x-transition:enter-end="translate-y-0"
                x-transition:leave="transition duration-150 ease-in motion-reduce:duration-0"
                x-transition:leave-start="translate-y-0"
                x-transition:leave-end="translate-y-full"
                role="dialog"
                aria-modal="true"
                aria-label="End the round"
                class="fixed inset-x-0 bottom-0 z-[70] mx-auto w-full max-w-lg rounded-t-2xl border border-b-0 border-line bg-surface pb-[env(safe-area-inset-bottom)] shadow-2xl dark:border-line-dark dark:bg-surface-dark"
            >
                <div class="mx-auto mt-2 mb-1 h-1 w-10 rounded-full bg-line dark:bg-line-dark" aria-hidden="true"></div>
                <div class="space-y-4 p-5 text-center">
                    <div>
                        <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">End for both of you?</p>
                        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $session->countsIfEndedNow() ? 'Scores so far are saved.' : 'Too short to count yet.' }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" x-on:click="confirming = false" class="cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">Keep going</button>
                        <button type="button" wire:click="end" x-on:click="confirming = false" wire:loading.attr="disabled" class="cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">End</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
