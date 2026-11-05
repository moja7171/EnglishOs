<?php

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Services\AiFailure;
use App\Services\AiPartner;
use App\Services\AiPractices;
use App\Services\GroqClient;
use App\Services\SpokenAnswerChecker;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public AiPractice $practice;

    /** The learner's recording, sent by <x-voice-recorder>. */
    public $audioFile = null;

    public ?string $error = null;

    public function mount(AiPractice $practice): void
    {
        abort_unless($practice->learner_id === auth()->id(), 403);

        $this->practice = $practice;
    }

    /**
     * What the learner just said. Null, with a line to show, when nothing
     * could be heard: they stay on the card and can try again or skip.
     */
    private function hear(): ?string
    {
        $this->error = null;
        $this->validate(['audioFile' => ['required', 'file', 'extensions:webm,ogg,mp3,wav,m4a', 'max:20480']]);

        try {
            $heard = trim(app(GroqClient::class)->transcribe($this->audioFile->getRealPath()));
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return null;
        } finally {
            $this->audioFile = null;
        }

        if ($heard === '') {
            $this->error = 'I couldn’t hear you. Try again.';

            return null;
        }

        return $heard;
    }

    /**
     * The recorder's one way in: a card's answer, a question for the AI
     * or the reason in Would You Rather.
     */
    public function submitAnswer(): void
    {
        if ($this->practice->isFinished()) {
            return;
        }

        match (true) {
            $this->practice->game() === PairGame::GAME_WHO && $this->practice->isGameStage() => $this->askAboutSecret(),
            $this->practice->isGameStage() => $this->sayWhy(),
            default => $this->answerCard(),
        };
    }

    private function answerCard(): void
    {
        $card = $this->practice->currentCard();

        if ($card === null || $this->practice->current !== null || ! $this->practice->learnerAnswers() || ($heard = $this->hear()) === null) {
            return;
        }

        if ($this->practice->stage === AiPractice::STAGE_SPEAKING) {
            $this->practice->recordAnswer($heard, null, $this->oneLineOn($card['question'] ?? '', $heard));

            return;
        }

        $this->practice->recordAnswer($heard, AiPractice::saidCorrectly($heard, $card['answer']));
    }

    /**
     * Never in the way: with no line the practice just goes on.
     */
    private function oneLineOn(string $prompt, string $heard): ?string
    {
        try {
            return app(SpokenAnswerChecker::class)->quickFeedback($prompt, $heard, auth()->user()->levelDescription()) ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Who am I, the AI holding the secret: the learner's question gets its
     * answer; naming the secret, or running out of questions, ends the turn.
     */
    private function askAboutSecret(): void
    {
        $current = $this->practice->current ?? ['qa' => []];

        if (! $this->practice->aiHoldsSecret() || isset($current['won']) || ($heard = $this->hear()) === null) {
            return;
        }

        try {
            $reply = app(AiPartner::class)->answerAboutSecret($this->practice->currentCard()['word'], $heard, $current['qa'], auth()->user()->levelDescription());
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $current['qa'][] = ['q' => $heard, 'a' => $reply['answer']];

        if ($reply['guessed']) {
            $current['won'] = true;
            $this->practice->score++;
        } elseif (count($current['qa']) >= AiPractice::MAX_QUESTIONS) {
            $current['won'] = false;
        }

        $this->practice->current = $current;
        $this->practice->answered++;
        $this->practice->save();
    }

    /**
     * Who am I, the learner holding the secret: the AI's next question.
     */
    public function aiAsks(): void
    {
        $this->error = null;
        $current = $this->practice->current ?? ['qa' => []];

        try {
            $question = app(AiPartner::class)->nextQuestion($this->practice->currentCard()['category'] ?? 'something', $current['qa'], auth()->user()->levelDescription());
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $current['qa'][] = ['q' => $question, 'a' => null];
        $this->practice->update(['current' => $current]);
    }

    public function answerAi(string $answer): void
    {
        $current = $this->practice->current;

        if ($this->practice->aiHoldsSecret() || ! in_array($answer, PairGame::ANSWERS, true) || blank($current['qa'] ?? null) || isset($current['won'])) {
            return;
        }

        $current['qa'][array_key_last($current['qa'])]['a'] = $answer;

        if (count($current['qa']) >= AiPractice::MAX_QUESTIONS) {
            $current['won'] = false;
            $this->practice->update(['current' => $current]);

            return;
        }

        $this->practice->update(['current' => $current]);
        $this->aiAsks();
    }

    /**
     * The AI named the learner's secret: a point for the two of you.
     */
    public function aiGotIt(): void
    {
        $current = $this->practice->current;

        if ($this->practice->aiHoldsSecret() || blank($current['qa'] ?? null) || isset($current['won'])) {
            return;
        }

        $this->practice->update(['current' => [...$current, 'won' => true], 'score' => $this->practice->score + 1, 'answered' => $this->practice->answered + 1]);
    }

    /**
     * Would You Rather: the learner's side and their guess of the AI's; a
     * right guess scores and the AI says why it picked its side.
     */
    public function pickSide(string $mine, string $guess): void
    {
        $card = $this->practice->currentCard();

        if ($this->practice->game() !== PairGame::GAME_RATHER || ! $this->practice->isGameStage() || $this->practice->current !== null
            || ! in_array($mine, PairGame::SIDES, true) || ! in_array($guess, PairGame::SIDES, true)) {
            return;
        }

        $ai = PairGame::SIDES[random_int(0, 1)];

        try {
            $reason = app(AiPartner::class)->reasonFor($card['word'], $card[$ai], auth()->user()->levelDescription());
        } catch (Throwable) {
            $reason = 'I’d rather pick this one. It sounds better to me.';
        }

        $this->practice->update([
            'current' => ['mine' => $mine, 'guess' => $guess, 'ai' => $ai, 'reason' => $reason],
            'score' => $this->practice->score + ($guess === $ai ? 1 : 0),
        ]);
    }

    private function sayWhy(): void
    {
        $current = $this->practice->current;

        if ($current === null || isset($current['heard']) || ($heard = $this->hear()) === null) {
            return;
        }

        $this->practice->update([
            'current' => [...$current, 'heard' => $heard, 'feedback' => $this->oneLineOn($this->practice->currentCard()['word'], $heard)],
            'answered' => $this->practice->answered + 1,
        ]);
    }

    public function next(): void
    {
        $this->error = null;
        $this->audioFile = null;
        $this->practice->next();

        if (! $this->practice->isFinished() && $this->practice->isGameStage() && $this->practice->game() === PairGame::GAME_WHO && ! $this->practice->aiHoldsSecret()) {
            $this->aiAsks();
        }
    }

    public function end(): void
    {
        $this->practice->finish();
    }

    public function again(): void
    {
        $next = app(AiPractices::class)->start(auth()->user(), $this->practice->kind, $this->practice->days);

        if ($next) {
            $this->redirectRoute('ai-practice.show', $next, navigate: true);
        }
    }
};
?>

@php
    $stages = $practice->stages();
    $isRound = $practice->kind === AiPractice::KIND_PRACTICE;
    $stage = $practice->stage;
    $card = $practice->currentCard();
    $current = $practice->current;
    $game = $practice->game();
    $partLabels = [AiPractice::STAGE_VOCABULARY => 'Words', AiPractice::STAGE_GRAMMAR => 'Grammar', AiPractice::STAGE_SPEAKING => 'Speaking', AiPractice::STAGE_GAME => $game ? PairGame::info($game)['name'] : 'Game'];
    $backHref = route('friends.tara');
@endphp

<div class="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 p-4">
    @if ($practice->isFinished())
        <div class="card space-y-4 p-6 text-center">
            <p class="animate-trophy-pop text-4xl" aria-hidden="true">🎉</p>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Well done!</h1>
            <div>
                <p class="font-display text-5xl font-extrabold text-accent-ink tabular-nums dark:text-accent-ink-dark">{{ $practice->score }}</p>
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">points</p>
            </div>
            @if (($practice->deck['missed_words'] ?? []) !== [])
                <p class="rounded-xl bg-surface-sunken p-3 text-sm text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">
                    <span class="text-ink-faint dark:text-ink-faint-dark">Saved for review ·</span> {{ collect($practice->deck['missed_words'])->pluck('answer')->join(', ') }}
                </p>
            @endif
            <button type="button" wire:click="again" wire:loading.attr="disabled" wire:target="again" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Practice again</button>
            <a href="{{ $backHref }}" wire:navigate class="block text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Done</a>
        </div>
    @else
        {{-- The same top bar as a round with a friend, with no clock: Tara is never in a hurry. --}}
        <header class="space-y-3">
            <div class="flex items-center gap-1">
                <button type="button" wire:click="end" wire:confirm="End this practice?" aria-label="End practice" class="-ms-2 inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-soft hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-x-mark', 'h-6 w-6')</button>
                <div class="flex flex-1 gap-1" role="progressbar" aria-label="{{ $partLabels[$stage] }}">
                    @foreach ($stages as $part)
                        @php
                            $partIndex = array_search($part, $stages, true);
                            $nowIndex = array_search($stage, $stages, true);
                        @endphp
                        <div wire:key="bar-{{ $part }}" class="h-2.5 flex-1 overflow-hidden rounded-full bg-line dark:bg-line-dark">
                            <div class="h-full rounded-full bg-success transition-all duration-500 dark:bg-success-dark" style="width: {{ $partIndex < $nowIndex ? 100 : ($partIndex === $nowIndex ? round($practice->position / max(1, count($practice->cards())) * 100) : 0) }}%"></div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="flex items-center justify-between gap-2 text-[13px] font-extrabold text-ink-soft dark:text-ink-soft-dark">
                <span>{{ $partLabels[$stage] }}</span>
                <span class="flex items-center gap-1.5"><x-tara-avatar class="h-5 w-5" /> With Tara · {{ $practice->score }}</span>
            </div>
        </header>

        @if ($error)
            <p class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $error }}</p>
        @endif

        @if ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_WHO)
            @include('components.ai-practice.partials.who-am-i')
        @elseif ($stage === AiPractice::STAGE_GAME)
            @include('components.ai-practice.partials.would-you-rather')
        @else
            @include('components.ai-practice.partials.card')
        @endif
    @endif
</div>
