<?php

use App\Models\PairPracticeSession;
use Livewire\Component;

new class extends Component
{
    public PairPracticeSession $session;

    /** The judge has uncovered the current card's answer. */
    public bool $revealed = false;

    /** Speaking: the asker asked for a longer answer. */
    public bool $askedForMore = false;

    /** @var list<string> Speaking: target words the asker heard. */
    public array $heardWords = [];

    public function mount(PairPracticeSession $session): void
    {
        abort_unless($session->isAccessibleBy(auth()->user()), 403);

        $this->session = $session;
    }

    public function reveal(): void
    {
        $this->revealed = true;
    }

    public function judge(bool $correct): void
    {
        $this->authorizePlayer();

        if ($this->session->stage === PairPracticeSession::STAGE_SPEAKING) {
            return;
        }

        $this->session->record($correct ? 1 : 0, $correct);
        $this->resetCard();
    }

    public function askForMore(): void
    {
        $this->askedForMore = true;
    }

    /**
     * Speaking: one point for every target word the asker heard.
     */
    public function nextQuestion(): void
    {
        $this->authorizePlayer();

        if ($this->session->stage !== PairPracticeSession::STAGE_SPEAKING) {
            return;
        }

        $words = $this->session->currentCard()['words'] ?? [];
        $this->session->record(count(array_intersect($words, $this->heardWords)), null);
        $this->resetCard();
    }

    /**
     * Called by the timer. A few seconds of slack for clock drift, but an
     * early call never skips a stage.
     */
    public function timeUp(): void
    {
        $this->authorizePlayer();

        if ($this->session->stage_ends_at && now()->addSeconds(3)->greaterThanOrEqualTo($this->session->stage_ends_at)) {
            $this->session->advanceStage();
            $this->resetCard();
        }
    }

    public function end(): void
    {
        $this->authorizePlayer();
        $this->session->finish();
    }

    private function resetCard(): void
    {
        $this->revealed = false;
        $this->askedForMore = false;
        $this->heardWords = [];
    }

    private function authorizePlayer(): void
    {
        abort_unless($this->session->isAccessibleBy(auth()->user()), 403);
    }
};
?>

@php
    $stage = $session->stage;
    $card = $session->currentCard();
    $speaker = $session->speaker();
    $judge = $session->judge();
    $stageLabels = [
        PairPracticeSession::STAGE_VOCABULARY => 'Words',
        PairPracticeSession::STAGE_GRAMMAR => 'Grammar',
        PairPracticeSession::STAGE_SPEAKING => 'Speaking',
    ];
@endphp

<div class="mx-auto max-w-xl space-y-4 p-4 sm:p-6">
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
    @else
        <header class="flex items-center gap-3">
            <a href="{{ route('pair-practice.index') }}" wire:navigate class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-faint hover:bg-surface-sunken dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark" title="Back">@svg('heroicon-o-chevron-left', 'h-5 w-5')</a>
            <p class="flex-1 text-sm font-semibold text-ink dark:text-ink-dark">
                {{ $stageLabels[$stage] ?? '' }}
                <span class="text-ink-faint dark:text-ink-faint-dark">· {{ min($session->positionInStage() + 1, $session->cardsInStage()) }}/{{ $session->cardsInStage() }}</span>
            </p>
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
                        if (this.left === 0 && ! this.fired) { this.fired = true; this.$wire.timeUp(); }
                    },
                }"
                x-text="Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0')"
                class="rounded-full bg-surface-sunken px-3 py-1 font-mono text-sm font-semibold text-ink tabular-nums dark:bg-surface-sunken-dark dark:text-ink-dark"
            ></span>
        </header>

        @if ($card)
            <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">
                @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                    <span class="font-semibold text-ink dark:text-ink-dark">{{ $judge->name }}</span> asks · <span class="font-semibold text-ink dark:text-ink-dark">{{ $speaker->name }}</span> answers
                @else
                    🎤 <span class="font-semibold text-ink dark:text-ink-dark">{{ $speaker->name }}</span> says · ⚖️ <span class="font-semibold text-ink dark:text-ink-dark">{{ $judge->name }}</span> checks
                @endif
            </p>

            <div wire:key="card-{{ count($session->results ?? []) }}" class="card space-y-4 p-5">
                @if ($stage === PairPracticeSession::STAGE_VOCABULARY)
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it in English</p>
                    <p dir="rtl" class="text-center font-display text-2xl font-bold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                @elseif ($stage === PairPracticeSession::STAGE_GRAMMAR)
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Say it correctly</p>
                    <p class="text-center text-lg font-semibold text-ink dark:text-ink-dark">{{ $card['prompt'] }}</p>
                @else
                    <p class="text-center font-display text-xl font-bold text-ink dark:text-ink-dark">{{ $card['question'] }}</p>
                    @if ($askedForMore)
                        <p class="text-center text-sm font-semibold text-accent-ink dark:text-accent-ink-dark">Tell me more — why? Give an example.</p>
                    @endif
                @endif

                @if ($revealed)
                    <div class="rounded-xl bg-surface-sunken p-3 text-center dark:bg-surface-sunken-dark">
                        <p class="text-base font-semibold text-ink dark:text-ink-dark">{{ $card['answer'] }}</p>
                        @if ($stage === PairPracticeSession::STAGE_VOCABULARY && filled($card['example'] ?? null))
                            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $card['example'] }}</p>
                        @endif
                    </div>
                @else
                    <button type="button" wire:click="reveal" class="w-full cursor-pointer rounded-xl border border-dashed border-line px-3 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                        {{ $judge->name }}: {{ $stage === PairPracticeSession::STAGE_SPEAKING ? 'show a sample answer' : 'show the answer' }}
                    </button>
                @endif

                @if ($stage === PairPracticeSession::STAGE_SPEAKING && ($card['words'] ?? []) !== [])
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
            </div>

            @if ($stage === PairPracticeSession::STAGE_SPEAKING)
                <div class="flex gap-2">
                    @unless ($askedForMore)
                        <button type="button" wire:click="askForMore" class="flex-1 cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">Say more</button>
                    @endunless
                    <button type="button" wire:click="nextQuestion" wire:loading.attr="disabled" class="flex-1 cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Next · swap</button>
                </div>
            @elseif ($revealed)
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="judge(false)" wire:loading.attr="disabled" class="cursor-pointer rounded-full border border-line px-4 py-3 text-sm font-semibold text-ink-soft hover:bg-surface-sunken disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">✗ Not yet</button>
                    <button type="button" wire:click="judge(true)" wire:loading.attr="disabled" class="cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">✓ Right</button>
                </div>
            @endif

            <p class="text-center text-xs text-ink-faint dark:text-ink-faint-dark">{{ $card['source'] ?? '' }}</p>
        @endif

        <button type="button" wire:click="end" wire:confirm="End this round now?" class="mx-auto block cursor-pointer text-xs font-semibold text-ink-faint hover:text-ink-soft dark:text-ink-faint-dark dark:hover:text-ink-soft-dark">End round</button>
    @endif
</div>
