<?php

namespace App\Models\Concerns;

use App\Models\AskAnswerItem;
use App\Models\User;
use App\Services\AskAnswerStep;

/**
 * The Ask & Answer rules of a PairGame. A turn is one round (one card =
 * one situation): the turn's "explainer" asks, then the friend answers.
 * Each says one sentence by voice, which is checked straight away
 * (AskAnswerChecker): right first time earns a point, right after one fix
 * half a point, and a second miss passes on. The speaker sees the Persian
 * line; only the other phone shows the English, to help.
 *
 * Round state lives in turn['current']['qa'][step], step being 'question'
 * then 'answer': tries, unchecked, status (ok / fixed / passed / unchecked),
 * heard, correct, why, extra, points and the level it was played at.
 */
trait PlaysAskAnswer
{
    public function isAskAnswer(): bool
    {
        return $this->game === self::GAME_ASK;
    }

    /** The two steps of a round, in order. */
    public const ASK_STEPS = [self::PHASE_QUESTION, self::PHASE_ANSWER];

    /** Half a point for the extra detail said as well. */
    public const POINTS_EXTRA = AskAnswerStep::POINTS_EXTRA;

    /**
     * The levels (1-3) $user plays at: the questions they ask and the
     * answers they give.
     *
     * @return array{q: int, a: int}
     */
    public function askLevels(User $user): array
    {
        $levels = $this->rules['levels'][$user->id] ?? [];

        return ['q' => $this->clampLevel($levels['q'] ?? 1), 'a' => $this->clampLevel($levels['a'] ?? 1)];
    }

    /**
     * $user picks the level of the questions they ask ('q') or of the
     * answers they give ('a') — before the game, or with Easier/Harder in
     * a round. Only their own.
     */
    public function setAskLevel(User $user, string $side, int $level): void
    {
        if (! $this->isAskAnswer() || ! $this->isAccessibleBy($user) || ! in_array($side, ['q', 'a'], true) || $this->isFinished() || $this->isCancelled()) {
            return;
        }

        $rules = $this->rules;
        $rules['levels'][$user->id] = [...$this->askLevels($user), $side => $this->clampLevel($level)];
        $this->rules = $rules;
        $this->save();
    }

    private function clampLevel(mixed $level): int
    {
        return min(max((int) $level, min(AskAnswerItem::LEVELS)), max(AskAnswerItem::LEVELS));
    }

    /**
     * Who says a sentence now: the asker in the question step, the friend
     * in the answer step.
     */
    public function askSpeaker(): ?User
    {
        return match ($this->phase()) {
            self::PHASE_QUESTION => $this->explainer(),
            self::PHASE_ANSWER => $this->partnerFor($this->explainer()),
            default => null,
        };
    }

    /**
     * What the speaker is asked to say now, at the speaker's own level:
     * the Persian lines and the English target (their partner sees the
     * English).
     *
     * @return array{fa: string, fa_extra: ?string, en: string, en_extra: ?string, alt: list<string>, wrong: list<array{text: string, why: string}>}|null
     */
    public function askLevel(): ?array
    {
        $card = $this->currentCard();
        $speaker = $this->askSpeaker();

        if ($card === null || $speaker === null) {
            return null;
        }

        $isQuestion = $this->phase() === self::PHASE_QUESTION;
        $side = $isQuestion ? 'questions' : 'answers';
        $level = $this->askLevels($speaker)[$isQuestion ? 'q' : 'a'];
        $levels = $card[$side] ?? [];

        for ($try = $level; $try >= 1; $try--) {
            if (isset($levels[$try])) {
                return $levels[$try];
            }
        }

        return $levels[1] ?? null;
    }

    /**
     * The level to check the next try against: after one miss the speaker
     * repeats the corrected sentence, so that is the target.
     *
     * @return array<string, mixed>|null
     */
    public function askLevelToCheck(): ?array
    {
        $level = $this->askLevel();
        $step = $this->askStep();

        return $level === null || $step === null ? $level : AskAnswerStep::levelToCheck($level, $step);
    }

    /**
     * The state of the step being said now.
     *
     * @return array{tries: int, unchecked: int, status: ?string, heard: ?string, correct: ?string, why: ?string, extra: bool, points: int, level: int}|null
     */
    public function askStep(): ?array
    {
        $phase = $this->phase();

        if ($phase === null || ! in_array($phase, self::ASK_STEPS, true)) {
            return null;
        }

        return $this->currentTurn()['current']['qa'][$phase] ?? AskAnswerStep::fresh();
    }

    /**
     * The speaker's sentence was checked (see AskAnswerChecker): right
     * ends the step, a miss shows the right sentence to repeat, a second
     * miss — or two sentences nobody could check — passes on.
     *
     * @param  array{status: string, extra: bool, correct: string, why: ?string}  $result
     */
    public function recordSpoken(User $user, string $heard, array $result): void
    {
        $speaker = $this->askSpeaker();
        $step = $this->askStep();

        if (! $this->isAskAnswer() || ! $this->isPlaying() || $speaker === null || ! $speaker->is($user) || $step === null || $step['status'] !== null) {
            return;
        }

        $this->storeAskStep(AskAnswerStep::apply($step, $heard, $result, $this->askLevels($user)[$this->phase() === self::PHASE_QUESTION ? 'q' : 'a']));
    }

    /**
     * The speaker passes: the right sentence stays on screen for both and
     * the round moves on.
     */
    public function passStep(User $user): void
    {
        $speaker = $this->askSpeaker();
        $step = $this->askStep();

        if (! $this->isAskAnswer() || ! $this->isPlaying() || $speaker === null || ! $speaker->is($user) || $step === null || $step['status'] !== null) {
            return;
        }

        $this->storeAskStep(AskAnswerStep::pass($step, $this->askLevels($user)[$this->phase() === self::PHASE_QUESTION ? 'q' : 'a'], $this->askLevel()['en'] ?? null));
    }

    /**
     * Saves the step; a step that ended moves the round on: the question
     * is followed by the answer, and the answer closes the round.
     *
     * @param  array<string, mixed>  $step
     */
    private function storeAskStep(array $step): void
    {
        $turns = $this->turns;
        $last = array_key_last($turns);
        $turns[$last]['current']['qa'][$this->phase()] = $step;
        $this->turns = $turns;

        if ($step['status'] === null) {
            $this->save();

            return;
        }

        if ($this->phase() === self::PHASE_QUESTION) {
            $turns[$last]['current']['phase'] = self::PHASE_ANSWER;
            $this->turns = $turns;
            $this->save();

            return;
        }

        $this->closeTurn();
    }

    /**
     * The points of a round: both steps together.
     *
     * @param  array<string, mixed>  $current
     */
    private function askAnswerPoints(array $current): int
    {
        return (int) collect(self::ASK_STEPS)->sum(fn (string $step) => $current['qa'][$step]['points'] ?? 0);
    }

    /**
     * What the result screen lists: every round, who asked, the sentences
     * said and the corrections.
     *
     * @return list<array{asker_id: int, card: array<string, mixed>, qa: array<string, array<string, mixed>>, points: int}>
     */
    public function askRounds(): array
    {
        return collect($this->turns ?? [])
            ->flatMap(fn (array $turn) => collect($turn['cards'])->map(fn (array $played) => [
                'asker_id' => $turn['explainer_id'],
                'card' => $this->deck[$played['card']] ?? [],
                'qa' => $played['qa'] ?? [],
                'points' => $played['points'],
            ]))
            ->values()
            ->all();
    }

    /**
     * The sentences a learner had to be corrected on or passed on, with
     * the right version — what they take away from the game.
     *
     * @return list<array{said: ?string, correct: string, why: ?string}>
     */
    public function askFixesFor(User $user): array
    {
        return collect($this->askRounds())
            ->flatMap(fn (array $round) => collect($round['qa'])
                ->filter(fn (array $step, string $name) => in_array($step['status'] ?? null, ['fixed', 'passed'], true) && filled($step['correct'] ?? null)
                    && ($name === self::PHASE_QUESTION ? $round['asker_id'] === $user->id : $round['asker_id'] !== $user->id))
                ->map(fn (array $step) => ['said' => $step['heard'] ?? null, 'correct' => $step['correct'], 'why' => $step['why'] ?? null]))
            ->values()
            ->all();
    }

    /**
     * Steps $user said right (first try or after a fix).
     */
    public function askCreditFor(User $user): int
    {
        return collect($this->askRounds())->sum(fn (array $round) => collect($round['qa'])
            ->filter(fn (array $step, string $name) => in_array($step['status'] ?? null, ['ok', 'fixed'], true)
                && ($name === self::PHASE_QUESTION ? $round['asker_id'] === $user->id : $round['asker_id'] !== $user->id))
            ->count());
    }

    public function otherPlayerIdOf(int $userId): int
    {
        return $userId === $this->user_a_id ? $this->user_b_id : $this->user_a_id;
    }
}
