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
        $this->prepareSpeakingSlip();
    }

    /**
     * A speaking card Teddy slips on: the AI writes his answer with one
     * small mistake, once. With no slip to show, he just says the card's
     * answer.
     */
    private function prepareSpeakingSlip(): void
    {
        $practice = $this->practice;

        if ($practice->isFinished() || $practice->stage !== AiPractice::STAGE_SPEAKING || $practice->learnerAnswers() || ! $practice->teddySlips() || isset($practice->current['says'])) {
            return;
        }

        $card = $practice->currentCard();

        try {
            $slip = $this->teddy()->answerWithSlip($card['question'] ?? '', $card['answer'], auth()->user()->levelDescription());
        } catch (Throwable) {
            $slip = null;
        }

        if (blank($slip)) {
            $slips = $practice->deck['slips'] ?? [];
            $slips[AiPractice::STAGE_SPEAKING] = array_values(array_diff($slips[AiPractice::STAGE_SPEAKING] ?? [], [$practice->position]));
            $practice->update(['deck' => [...$practice->deck, 'slips' => $slips]]);

            return;
        }

        $practice->update(['current' => ['says' => $slip]]);
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
            ! $this->practice->isGameStage() => $this->answerCard(),
            $this->practice->game() === PairGame::GAME_WHO => $this->askAboutSecret(),
            $this->practice->game() === PairGame::GAME_MINUTE => $this->speakInMinute(),
            $this->practice->game() === PairGame::GAME_STORY => $this->tellStory(),
            $this->practice->game() === PairGame::GAME_TRUTHS => $this->speakInTruths(),
            $this->practice->game() === PairGame::GAME_TABOO => $this->speakInTaboo(),
            default => $this->sayWhy(),
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
     * Teddy's turn on a card: the learner's verdict on what he said.
     */
    public function judgeTeddy(string $verdict): void
    {
        if ($this->practice->isFinished() || $this->practice->isGameStage() || $this->practice->currentCard() === null) {
            return;
        }

        if ($this->practice->learnerAnswers() || isset($this->practice->current['judged']) || ! in_array($verdict, ['right', 'mistake'], true)) {
            return;
        }

        $this->practice->recordJudgement($verdict);
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
     * Teddy, playing at the level this practice was set to.
     */
    private function teddy(): AiPartner
    {
        return app(AiPartner::class)->atLevel($this->practice->level());
    }

    /**
     * What Teddy says, or a plain line when the AI can't answer: her
     * words are never what holds the learner up.
     *
     * @param  \Closure(AiPartner): string  $saying
     */
    private function teddySays(\Closure $saying, string $plainLine): string
    {
        try {
            return $saying($this->teddy()) ?: $plainLine;
        } catch (Throwable) {
            return $plainLine;
        }
    }

    /**
     * Just a Minute. On the learner's turn: their talk (the target words
     * are found with no AI call, one point each, and Teddy asks about it),
     * then their answer. On Teddy's turn: their question about her talk.
     */
    private function speakInMinute(): void
    {
        $current = $this->practice->current;
        $card = $this->practice->currentCard();
        $mine = $this->practice->isLearnersGameTurn();

        if (isset($current['reply']) || (! $mine && blank($current['talk'] ?? null)) || ($heard = $this->hear()) === null) {
            return;
        }

        $level = auth()->user()->levelDescription();

        if ($mine && $current === null) {
            $used = AiPractice::wordsIn($heard, $card['words']);
            $question = $this->teddySays(fn (AiPartner $teddy) => $teddy->questionAbout($card['word'], $heard, $level), 'That sounds nice! Can you tell me more?');
            $this->practice->update(['current' => ['heard' => $heard, 'used' => $used, 'question' => $question], 'score' => $this->practice->score + count($used), 'answered' => $this->practice->answered + 1]);

            return;
        }

        if ($mine) {
            $this->practice->update(['current' => [...$current, 'reply' => $heard], 'answered' => $this->practice->answered + 1]);

            return;
        }

        $reply = $this->teddySays(fn (AiPartner $teddy) => $teddy->replyToQuestion($current['talk'], $heard, $level), 'Good question! I need to think about that one.');
        $this->practice->update(['current' => [...$current, 'question' => $heard, 'reply' => $reply], 'score' => $this->practice->score + 1, 'answered' => $this->practice->answered + 1]);
    }

    /**
     * Just a Minute, Teddy's turn: her talk. The word chips count only the
     * words she really said.
     */
    public function aiTalks(): void
    {
        $this->error = null;
        $card = $this->practice->currentCard();

        $level = auth()->user()->levelDescription();
        $slip = null;

        try {
            if ($this->practice->teddySlips()) {
                try {
                    $slip = $this->teddy()->talkWithSlip($card['word'], $card['words'], $level);
                } catch (Throwable) {
                    $slip = null;
                }
            }

            $talk = $slip === null ? $this->teddy()->talkAbout($card['word'], $card['words'], $level) : implode(' ', $slip['sentences']);
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $this->practice->update(['current' => [
            'talk' => $talk,
            'used' => AiPractice::wordsIn($talk, $card['words']),
            'ticked' => [],
            ...($slip === null ? [] : ['slip' => $slip]),
        ]]);
    }

    /**
     * Just a Minute, Teddy's turn: the learner taps the sentence they think
     * has the mistake, or -1 for "no mistake", once. They are always asked,
     * so being asked gives nothing away. A point for the right call.
     */
    public function pickTalkSlip(int $sentence): void
    {
        $current = $this->practice->current;

        if ($this->practice->game() !== PairGame::GAME_MINUTE || $this->practice->isLearnersGameTurn() || blank($current['talk'] ?? null) || isset($current['slip_pick'])) {
            return;
        }

        $sentences = $current['slip']['sentences'] ?? AiPractice::sentencesOf($current['talk']);
        $slip = $current['slip']['slip'] ?? -1;

        if ($sentence !== -1 && ! isset($sentences[$sentence])) {
            return;
        }

        if ($slip !== -1) {
            $this->practice->logSlip($sentences[$slip], $current['slip']['fix'], $sentence === $slip);
        }

        $this->practice->update(['current' => [...$current, 'slip_pick' => $sentence], 'score' => $this->practice->score + ($sentence === $slip ? 1 : 0)]);
    }

    /**
     * Just a Minute, Teddy's turn: a word the learner heard, a point when
     * she really said it. A second tap takes it back.
     */
    public function tickWord(string $word): void
    {
        $current = $this->practice->current;

        if ($this->practice->game() !== PairGame::GAME_MINUTE || $this->practice->isLearnersGameTurn() || ! isset($current['talk'])
            || ! in_array($word, $this->practice->currentCard()['words'] ?? [], true)) {
            return;
        }

        $ticked = in_array($word, $current['ticked'], true);
        $points = in_array($word, $current['used'], true) ? ($ticked ? -1 : 1) : 0;

        $this->practice->update([
            'current' => [...$current, 'ticked' => $ticked ? array_values(array_diff($current['ticked'], [$word])) : [...$current['ticked'], $word]],
            'score' => $this->practice->score + $points,
        ]);
    }

    /**
     * Story Chain, the learner's turn: their line, a point when their word
     * is in it (no AI call), and Teddy goes on straight away.
     */
    private function tellStory(): void
    {
        $current = $this->practice->current ?? ['lines' => []];

        if (isset($current['ended']) || ($heard = $this->hear()) === null) {
            return;
        }

        $card = $this->practice->currentCard();
        $this->practice->answered++;
        $this->practice->score += AiPractice::wordsIn($heard, [$card['word']]) === [] ? 0 : 1;
        $this->teddyGoesOn([...$current['lines'], ['who' => 'you', 'text' => $heard]]);
    }

    /**
     * Story Chain, Teddy's turn: her line with the next card's word, on the
     * last turn the ending. A plain line stands in when the AI can't answer.
     *
     * @param  list<array{who: string, text: string}>  $lines
     */
    private function teddyGoesOn(array $lines): void
    {
        $card = $this->practice->cards()[$this->practice->position + 1];
        $endsTheStory = $this->practice->position + 2 >= count($this->practice->cards());
        $story = collect([$card['line'], ...array_column($lines, 'text')])->join("\n");
        $level = auth()->user()->levelDescription();
        $slip = null;

        if ($this->practice->teddySlips() && ! $endsTheStory) {
            try {
                $slip = $this->teddy()->continueStoryWithSlip($story, $card['word'], $level);
            } catch (Throwable) {
                $slip = null;
            }
        }

        $line = $slip['line'] ?? $this->teddySays(
            fn (AiPartner $teddy) => $teddy->continueStory($story, $card['word'], $endsTheStory, $level),
            $endsTheStory ? 'And in the end, everybody was happy.' : 'Then something surprising happened.',
        );

        $this->practice->current = [
            'lines' => [...$lines, ['who' => 'teddy', 'text' => $line, ...($slip === null ? [] : ['fix' => $slip['fix']])]],
            ...($endsTheStory ? ['ended' => true] : []),
        ];
        $this->practice->position += $endsTheStory ? 1 : 2;
        $this->practice->save();
    }

    /**
     * Story Chain, once the story is told: the learner taps the line of
     * Teddy's they think had a slip, or -1 for "no mistake". They are always
     * asked, so being asked gives nothing away. A point for the right call.
     */
    public function pickStorySlip(int $lineIndex): void
    {
        $current = $this->practice->current;

        if ($this->practice->game() !== PairGame::GAME_STORY || ! isset($current['ended']) || isset($current['slip_pick'])) {
            return;
        }

        $line = $current['lines'][$lineIndex] ?? null;

        if ($lineIndex !== -1 && ($line === null || $line['who'] === 'you')) {
            return;
        }

        $slipIndex = collect($current['lines'])->search(fn (array $other) => isset($other['fix']));
        $right = $lineIndex === -1 ? $slipIndex === false : isset($line['fix']);

        if ($slipIndex !== false) {
            $this->practice->logSlip($current['lines'][$slipIndex]['text'], $current['lines'][$slipIndex]['fix'], $lineIndex === $slipIndex);
        }

        $this->practice->update(['current' => [...$current, 'slip_pick' => $lineIndex], 'score' => $this->practice->score + ($right ? 1 : 0)]);
    }

    /**
     * Two Truths, the learner's turn: which of their three things is the
     * lie, marked before they speak.
     */
    public function markLie(int $statement): void
    {
        if ($this->practice->game() !== PairGame::GAME_TRUTHS || ! $this->practice->isLearnersGameTurn() || isset($this->practice->current['heard'])
            || ! in_array($statement, range(1, PairGame::STATEMENTS), true)) {
            return;
        }

        $this->practice->update(['current' => ['lie' => $statement]]);
    }

    /**
     * Two Truths. On the learner's turn: their three things, and Teddy's
     * guess of the lie, a point for the two of you when she finds it. On
     * her turn: a question about her three things.
     */
    private function speakInTruths(): void
    {
        $current = $this->practice->current;
        $card = $this->practice->currentCard();
        $mine = $this->practice->isLearnersGameTurn();

        $canSpeak = $mine
            ? isset($current['lie']) && ! isset($current['heard'])
            : isset($current['statements']) && ! isset($current['picked']) && count($current['asked']) < AiPractice::TRUTHS_QUESTIONS;

        if (! $canSpeak || ($heard = $this->hear()) === null) {
            return;
        }

        $level = auth()->user()->levelDescription();

        if (! $mine) {
            $answer = $this->teddySays(fn (AiPartner $teddy) => $teddy->keepTheLieSafe($current['statements'], $current['lie'], $heard, $level), 'Ha! I can’t tell you that.');
            $this->practice->update(['current' => [...$current, 'asked' => [...$current['asked'], ['q' => $heard, 'a' => $answer]]], 'answered' => $this->practice->answered + 1]);

            return;
        }

        try {
            $guess = $this->teddy()->findTheLie($card['word'], $heard, $level);
        } catch (Throwable) {
            $pick = random_int(1, PairGame::STATEMENTS);
            $guess = ['pick' => $pick, 'line' => "Hmm, I think number {$pick} is the lie."];
        }

        $this->practice->update([
            'current' => [...$current, 'heard' => $heard, ...$guess],
            'score' => $this->practice->score + ($guess['pick'] === $current['lie'] ? 1 : 0),
            'answered' => $this->practice->answered + 1,
        ]);
    }

    /**
     * Two Truths, Teddy's turn: her three things.
     */
    public function aiTellsThree(): void
    {
        $this->error = null;
        $card = $this->practice->currentCard();

        try {
            $three = $this->teddy()->threeThings($card['word'], $card['hint'] ?? null, auth()->user()->levelDescription());
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $this->practice->update(['current' => [...$three, 'asked' => []]]);
    }

    /**
     * Two Truths, Teddy's turn: the learner's pick, a point when it's her lie.
     */
    public function pickLie(int $statement): void
    {
        $current = $this->practice->current;

        if ($this->practice->game() !== PairGame::GAME_TRUTHS || $this->practice->isLearnersGameTurn() || ! isset($current['statements'])
            || isset($current['picked']) || ! in_array($statement, range(1, PairGame::STATEMENTS), true)) {
            return;
        }

        $this->practice->update([
            'current' => [...$current, 'picked' => $statement],
            'score' => $this->practice->score + ($statement === $current['lie'] ? 1 : 0),
            'answered' => $this->practice->answered + 1,
        ]);
    }

    /**
     * Taboo. On the learner's turn: their explanation (saying the word
     * only asks for another try) and Teddy's guess. On her turn: their
     * guess, and a wrong one brings the next clue. Right or not is decided
     * here, with no AI call; a right guess is a point for the two of you.
     */
    private function speakInTaboo(): void
    {
        $current = $this->practice->current ?? [];
        $word = $this->practice->currentCard()['word'];
        $mine = $this->practice->isLearnersGameTurn();

        if (isset($current['won']) || (! $mine && ! isset($current['clues'])) || ($heard = $this->hear()) === null) {
            return;
        }

        $this->practice->answered++;

        if ($mine && AiPractice::wordsIn($heard, [$word]) !== []) {
            $this->practice->save();
            $this->error = 'You said the word! Try another way.';

            return;
        }

        if ($mine) {
            try {
                $guess = $this->teddy()->guessTheWord($heard, $current['tries'] ?? [], auth()->user()->levelDescription());
            } catch (Throwable $e) {
                $this->practice->save();
                $this->error = AiFailure::checkMessage($e);

                return;
            }

            $tries = [...$current['tries'] ?? [], ['said' => $heard, 'guess' => $guess]];
            $this->endTabooTry(['tries' => $tries], AiPractice::namesWord($guess, $word, heardAloud: false), count($tries), AiPractice::TABOO_TRIES);

            return;
        }

        $guesses = [...$current['guesses'], $heard];
        $this->endTabooTry([...$current, 'guesses' => $guesses], AiPractice::namesWord($heard, $word), count($guesses), min(AiPractice::TABOO_TRIES, count($current['clues'])));
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function endTabooTry(array $current, bool $isRight, int $tries, int $triesAllowed): void
    {
        if ($isRight || $tries >= $triesAllowed) {
            $current['won'] = $isRight;
        }

        $this->practice->current = $current;
        $this->practice->score += $isRight ? 1 : 0;
        $this->practice->save();
    }

    /**
     * Taboo, Teddy's turn: her clues for the card's word.
     */
    public function aiGivesClues(): void
    {
        $this->error = null;

        try {
            $clues = $this->teddy()->cluesFor($this->practice->currentCard()['word'], auth()->user()->levelDescription());
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $this->practice->update(['current' => ['clues' => $clues, 'guesses' => []]]);
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

        $before = collect($current['qa'])->first(fn (array $row) => AiPractice::sameQuestion($row['q'], $heard));

        if ($before !== null) {
            $this->error = "You already asked that: {$before['a']}. Ask something new.";

            return;
        }

        try {
            $reply = $this->teddy()->answerAboutSecret($this->practice->currentCard()['word'], $heard, $current['qa'], auth()->user()->levelDescription());
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $row = ['q' => $heard, 'a' => $reply['answer']];

        if ($this->shouldFlip($current['qa'], $reply)) {
            $row = ['q' => $heard, 'a' => $reply['answer'] === 'yes' ? 'no' : 'yes', 'flip' => true, 'real' => $reply['answer']];
        }

        $current['qa'][] = $row;

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
     * Teddy slips on this secret: he gives one clear yes or no the wrong
     * way round, once, and never on the first question or the winning one.
     *
     * @param  list<array<string, mixed>>  $asked
     * @param  array{answer: string, guessed: bool}  $reply
     */
    private function shouldFlip(array $asked, array $reply): bool
    {
        return $this->practice->teddySlips()
            && $asked !== []
            && ! $reply['guessed']
            && in_array($reply['answer'], ['yes', 'no'], true)
            && ! collect($asked)->contains(fn (array $row) => ! empty($row['flip']))
            && count($asked) + 1 < AiPractice::MAX_QUESTIONS;
    }

    /**
     * Who am I, the learner holding Teddy's secret: "that answer is wrong".
     * A point when he really slipped there.
     */
    public function challengeAnswer(int $index): void
    {
        $current = $this->practice->current;
        $row = $current['qa'][$index] ?? null;

        if ($this->practice->game() !== PairGame::GAME_WHO || ! $this->practice->aiHoldsSecret() || $row === null || isset($row['challenged']) || isset($current['won'])) {
            return;
        }

        $current['qa'][$index] = [...$row, 'challenged' => true];

        if (! empty($row['flip'])) {
            $this->practice->logSlip("“{$row['q']}” → {$row['a']}", $row['real'], true, 'answer');
        }

        $this->practice->update(['current' => $current, 'score' => $this->practice->score + (empty($row['flip']) ? 0 : 1)]);
    }

    /**
     * Who am I, the learner holding the secret: the AI's next question.
     */
    public function aiAsks(): void
    {
        $this->error = null;
        $current = $this->practice->current ?? ['qa' => []];
        $category = $this->practice->currentCard()['category'] ?? 'something';
        $left = AiPractice::MAX_QUESTIONS - count($current['qa']);
        $question = null;
        $repeats = [];

        try {
            for ($try = 0; $try < 2 && $question === null; $try++) {
                $asked = $this->teddy()->nextQuestion($category, $current['qa'], auth()->user()->levelDescription(), $left, $repeats);

                if (collect($current['qa'])->contains(fn (array $row) => AiPractice::sameQuestion($row['q'], $asked))) {
                    $repeats[] = $asked;

                    continue;
                }

                $question = $asked;
            }
        } catch (Throwable $e) {
            $this->error = AiFailure::checkMessage($e);

            return;
        }

        $question ??= collect(AiPractice::FALLBACK_QUESTIONS)->first(fn (string $fallback) => ! collect($current['qa'])->contains(fn (array $row) => AiPractice::sameQuestion($row['q'], $fallback)))
            ?? 'Are you something special?';

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
            $reason = $this->teddy()->reasonFor($card['word'], $card[$ai], auth()->user()->levelDescription());
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
        $this->logMissedFlips();
        $this->practice->creditTheDay();

        if ($this->practice->isGameStage() && $this->practice->game() === PairGame::GAME_STORY && ! isset($this->practice->current['ended'])) {
            $this->teddyGoesOn($this->practice->current['lines'] ?? []);

            return;
        }

        $this->practice->next();

        if ($this->practice->isFinished()) {
            return;
        }

        if (! $this->practice->isGameStage()) {
            $this->prepareSpeakingSlip();

            return;
        }

        match (true) {
            $this->practice->game() === PairGame::GAME_WHO && ! $this->practice->aiHoldsSecret() => $this->aiAsks(),
            $this->practice->game() === PairGame::GAME_MINUTE && ! $this->practice->isLearnersGameTurn() => $this->aiTalks(),
            $this->practice->game() === PairGame::GAME_TRUTHS && ! $this->practice->isLearnersGameTurn() => $this->aiTellsThree(),
            $this->practice->game() === PairGame::GAME_TABOO && ! $this->practice->isLearnersGameTurn() => $this->aiGivesClues(),
            default => null,
        };
    }

    /**
     * Who am I, leaving a secret of Teddy's: a yes or no he gave the wrong
     * way round and the learner did not challenge was a slip they missed.
     */
    private function logMissedFlips(): void
    {
        if (! $this->practice->isGameStage() || $this->practice->game() !== PairGame::GAME_WHO || ! $this->practice->aiHoldsSecret()) {
            return;
        }

        foreach ($this->practice->current['qa'] ?? [] as $row) {
            if (! empty($row['flip']) && empty($row['challenged'])) {
                $this->practice->logSlip("“{$row['q']}” → {$row['a']}", $row['real'], false, 'answer');
            }
        }

        $this->practice->save();
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
    $backHref = route('friends.teddy');
@endphp

<div class="mx-auto flex min-h-dvh max-w-xl flex-col gap-4 p-4">
    @if ($practice->isFinished())
        <div class="card space-y-4 p-6 text-center">
            <x-feedback-cue kind="done" />
            <p class="animate-trophy-pop text-4xl" aria-hidden="true">🎉</p>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Well done!</h1>
            <div>
                <p class="font-display text-5xl font-extrabold text-accent-ink tabular-nums dark:text-accent-ink-dark">{{ $practice->score }}</p>
                <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">points</p>
            </div>
            @php
                $slips = $practice->slipCount();
                $missedSlips = collect($practice->deck['slip_log'] ?? [])->where('caught', false)->take(3);
                $tally = collect($practice->deck['tally'] ?? [])->map(fn (array $row, string $part) => ($partLabels[$part] ?? $part).' '.$row['right'].'/'.$row['total'])->values();
            @endphp
            @if ($tally->isNotEmpty() || $slips['total'] > 0)
                <div class="flex flex-wrap justify-center gap-2 text-sm font-bold">
                    @foreach ($tally as $line)
                        <span wire:key="tally-{{ $loop->index }}" class="rounded-full bg-surface-sunken px-3 py-1 text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">{{ $line }}</span>
                    @endforeach
                    @if ($slips['total'] > 0)
                        <span class="rounded-full bg-accent-soft px-3 py-1 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">Teddy’s slips you caught {{ $slips['caught'] }}/{{ $slips['total'] }}</span>
                    @endif
                </div>
            @endif
            @if ($missedSlips->isNotEmpty())
                <div class="space-y-2 rounded-xl bg-warning-soft p-3 text-start">
                    <p class="text-xs font-bold text-warning-ink">Teddy’s slips you missed</p>
                    @foreach ($missedSlips as $slip)
                        <p wire:key="missed-{{ $loop->index }}" class="text-sm font-semibold text-ink dark:text-ink-dark"><span class="text-warning-ink line-through">{{ $slip['said'] }}</span> → {{ $slip['fix'] }}</p>
                    @endforeach
                </div>
            @endif
            @if (($practice->deck['missed_words'] ?? []) !== [])
                <p class="rounded-xl bg-surface-sunken p-3 text-sm text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">
                    <span class="text-ink-faint dark:text-ink-faint-dark">Saved for review ·</span> {{ collect($practice->deck['missed_words'])->pluck('answer')->join(', ') }}
                </p>
            @endif
            <button type="button" wire:click="again" wire:loading.attr="disabled" wire:target="again" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-sm font-semibold text-white disabled:opacity-50 dark:bg-accent-dark">Practice again</button>
            <a href="{{ $backHref }}" wire:navigate class="block text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Done</a>
        </div>
    @else
        {{-- The same top bar as a round with a friend, with no clock: Teddy is never in a hurry. --}}
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
                <span class="flex items-center gap-1.5"><x-teddy-avatar class="h-5 w-5" /> With Teddy · {{ $practice->score }}</span>
            </div>
        </header>

        @if ($error)
            <p class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $error }}</p>
        @endif

        @if ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_WHO)
            @include('components.ai-practice.partials.who-am-i')
        @elseif ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_MINUTE)
            @include('components.ai-practice.partials.just-a-minute')
        @elseif ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_STORY)
            @include('components.ai-practice.partials.story-chain')
        @elseif ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_TRUTHS)
            @include('components.ai-practice.partials.two-truths')
        @elseif ($stage === AiPractice::STAGE_GAME && $game === PairGame::GAME_TABOO)
            @include('components.ai-practice.partials.taboo')
        @elseif ($stage === AiPractice::STAGE_GAME)
            @include('components.ai-practice.partials.would-you-rather')
        @else
            @include('components.ai-practice.partials.card')
        @endif
    @endif
</div>
