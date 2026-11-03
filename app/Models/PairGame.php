<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One game from the Games page: two friends, each on their own phone,
 * 5 or 10 minutes, one team score. Taboo is played in timed turns — one
 * explains (the only one who sees the card) while the other guesses —
 * and the two swap after every turn. The explainer taps what happened to
 * each card, so the guesser's phone never shows the word.
 */
#[Fillable(['user_a_id', 'user_b_id', 'started_by_id', 'ended_by_id', 'game', 'minutes', 'rules', 'deck', 'turns', 'stage', 'score', 'joined_at', 'finished_at'])]
class PairGame extends Model
{
    use HasFactory;

    public const GAME_TABOO = 'taboo';

    /**
     * Every game on the Games page, in the order of its tiles. Only the
     * playable ones can be picked; the others are shown as coming soon.
     *
     * @var list<string>
     */
    public const GAMES = [self::GAME_TABOO, 'who-am-i', 'picture-match', 'just-a-minute', 'story-chain', 'role-play', 'two-truths', 'would-you-rather'];

    /** @var list<string> */
    public const PLAYABLE = [self::GAME_TABOO];

    /** @var list<int> */
    public const LENGTHS = [5, 10];

    /**
     * A game's tile on the Games page: its name, one line, what it trains
     * and its photo (public/images/games/<slug>.jpg, from Pexels).
     *
     * @return array{name: string, line: string, tag: string, image: string, playable: bool}
     */
    public static function info(string $game): array
    {
        [$name, $line, $tag] = match ($game) {
            self::GAME_TABOO => ['Taboo', 'Explain it, don’t say it', 'Words'],
            'who-am-i' => ['Who am I?', 'Guess with yes/no questions', 'Questions'],
            'picture-match' => ['Picture Match', 'Describe it, they find it', 'Words'],
            'just-a-minute' => ['Just a Minute', 'Talk for a minute', 'Speaking'],
            'story-chain' => ['Story Chain', 'One sentence each', 'Speaking'],
            'role-play' => ['Role-play', 'Each has a secret goal', 'Speaking'],
            'two-truths' => ['Two Truths', 'Find the lie', 'Questions'],
            'would-you-rather' => ['Would You Rather', 'Pick a side, say why', 'Speaking'],
            default => ['Game', '', 'Speaking'],
        };

        return [
            'name' => $name,
            'line' => $line,
            'tag' => $tag,
            'image' => asset('images/games/'.$game.'.jpg'),
            'playable' => in_array($game, self::PLAYABLE, true),
        ];
    }

    public const STAGE_WAITING = 'waiting';

    /** Between turns: the next explainer starts their turn when ready. */
    public const STAGE_READY = 'ready';

    public const STAGE_PLAYING = 'playing';

    public const STAGE_DONE = 'done';

    public const STAGE_CANCELLED = 'cancelled';

    public const RESULT_GOT = 'got';

    public const RESULT_SKIP = 'skip';

    /** The card still on screen when the turn's time ran out. */
    public const RESULT_MISSED = 'missed';

    /**
     * Score is kept in half points: a word guessed is 2 (one word), a word
     * guessed after "Show first letter" is 1 (half), and a card whose
     * bonus words were all avoided earns 2 more.
     */
    public const POINTS_WORD = 2;

    public const POINTS_WITH_LETTER = 1;

    public const POINTS_BONUS = 2;

    public const INVITE_MINUTES = 10;

    public const RESUME_HOURS = 2;

    /** A turn's clock gets a little slack for a slow phone before it may be closed. */
    private const CLOCK_SLACK_SECONDS = 2;

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'deck' => 'array',
            'turns' => 'array',
            'minutes' => 'integer',
            'score' => 'integer',
            'joined_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_id');
    }

    /**
     * Every game of this pair, whichever of the two started it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForPair(Builder $query, User $a, User $b): void
    {
        [$lowId, $highId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $query->where('user_a_id', $lowId)->where('user_b_id', $highId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id));
    }

    /**
     * Games that can still be picked up: unfinished, recent, and not an
     * invite that expired before the friend joined.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('finished_at')
            ->where('stage', '!=', self::STAGE_CANCELLED)
            ->where('created_at', '>=', now()->subHours(self::RESUME_HOURS))
            ->where(fn (Builder $query) => $query
                ->where('stage', '!=', self::STAGE_WAITING)
                ->orWhere('created_at', '>', now()->subMinutes(self::INVITE_MINUTES)));
    }

    public function isAccessibleBy(User $user): bool
    {
        return $user->id === $this->user_a_id || $user->id === $this->user_b_id;
    }

    public function partnerFor(User $user): User
    {
        return $user->id === $this->user_a_id ? $this->userB : $this->userA;
    }

    public function isWaiting(): bool
    {
        return $this->stage === self::STAGE_WAITING;
    }

    public function isPlaying(): bool
    {
        return $this->stage === self::STAGE_PLAYING;
    }

    public function isFinished(): bool
    {
        return $this->stage === self::STAGE_DONE;
    }

    public function isCancelled(): bool
    {
        return $this->stage === self::STAGE_CANCELLED;
    }

    public function isExpired(): bool
    {
        return $this->isWaiting() && $this->created_at->lte(now()->subMinutes(self::INVITE_MINUTES));
    }

    public function inviteEndsAt(): Carbon
    {
        return $this->created_at->copy()->addMinutes(self::INVITE_MINUTES);
    }

    /**
     * The invited friend joins: the game waits for its first explainer —
     * whoever started it — to tap Start.
     */
    public function join(User $user): void
    {
        if (! $this->isWaiting() || $this->isExpired() || $user->id === $this->started_by_id || ! $this->isAccessibleBy($user)) {
            return;
        }

        $this->forceFill(['joined_at' => now(), 'stage' => self::STAGE_READY])->save();
    }

    /**
     * join() under a row lock, for a tap that lands from outside the game
     * (Join on an invite banner or the Friends page).
     */
    public function joinWithLock(User $user): void
    {
        if (! $this->isWaiting() || $user->id === $this->started_by_id) {
            return;
        }

        DB::transaction(function () use ($user) {
            $locked = self::lockForUpdate()->findOrFail($this->id);
            $locked->join($user);
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Seconds of one turn (90 for beginners, 60 later).
     */
    public function turnSeconds(): int
    {
        return (int) ($this->rules['turn_seconds'] ?? 60);
    }

    /**
     * How many turns the game has — always an even number, so both
     * explain equally often.
     */
    public function plannedTurns(): int
    {
        return (int) ($this->rules['turns'] ?? 4);
    }

    /**
     * Skips allowed in one turn, or null when skipping is free.
     */
    public function skipsPerTurn(): ?int
    {
        return isset($this->rules['skips']) ? (int) $this->rules['skips'] : null;
    }

    /**
     * Who explains turn $index: the one who started the game first, then
     * the two take turns.
     */
    public function explainerOf(int $index): User
    {
        $starter = $this->startedBy;

        return $index % 2 === 0 ? $starter : $this->partnerFor($starter);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentTurn(): ?array
    {
        return $this->isPlaying() ? (collect($this->turns ?? [])->last()) : null;
    }

    public function currentTurnIndex(): int
    {
        return max(0, count($this->turns ?? []) - ($this->isPlaying() ? 1 : 0));
    }

    /**
     * Who explains now — or, between turns, who explains next.
     */
    public function explainer(): User
    {
        return $this->explainerOf($this->currentTurnIndex());
    }

    /**
     * The card on the explainer's screen.
     *
     * @return array<string, mixed>|null
     */
    public function currentCard(): ?array
    {
        $turn = $this->currentTurn();

        return $turn === null ? null : ($this->deck[$turn['current']['card']] ?? null);
    }

    /**
     * The next card's place in the deck: the one after the last card shown.
     * The deck starts over if a long game ever runs through it.
     */
    private function nextCardIndex(): int
    {
        $last = -1;

        foreach ($this->turns ?? [] as $turn) {
            foreach ($turn['cards'] as $card) {
                $last = $card['card'];
            }

            if (isset($turn['current'])) {
                $last = $turn['current']['card'];
            }
        }

        return ($last + 1) % max(1, count($this->deck ?? []));
    }

    /**
     * The next explainer starts their turn: the clock runs from now.
     */
    public function startTurn(User $user): void
    {
        if ($this->stage !== self::STAGE_READY || ! $this->explainer()->is($user)) {
            return;
        }

        $card = $this->nextCardIndex();

        $this->turns = [...($this->turns ?? []), [
            'explainer_id' => $user->id,
            'started_at' => now()->getTimestamp(),
            'ends_at' => now()->addSeconds($this->turnSeconds())->getTimestamp(),
            'cards' => [],
            'current' => ['card' => $card, 'letter' => false, 'bonus_said' => false],
        ]];
        $this->stage = self::STAGE_PLAYING;
        $this->save();
    }

    public function turnEndsAt(): ?int
    {
        return $this->currentTurn()['ends_at'] ?? null;
    }

    public function skipsLeft(): ?int
    {
        $limit = $this->skipsPerTurn();
        $turn = $this->currentTurn();

        if ($limit === null || $turn === null) {
            return null;
        }

        return max(0, $limit - collect($turn['cards'])->where('result', self::RESULT_SKIP)->count());
    }

    /**
     * The explainer taps "got it" or Skip for the card on screen. $seen is
     * how many cards of this turn their phone had seen, so a double tap or
     * a stale screen changes nothing.
     */
    public function scoreCard(User $user, string $result, int $seen): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->explainer()->is($user) || count($turn['cards']) !== $seen || now()->getTimestamp() > $turn['ends_at'] + self::CLOCK_SLACK_SECONDS) {
            return;
        }

        if ($result === self::RESULT_SKIP && $this->skipsLeft() === 0) {
            return;
        }

        if (! in_array($result, [self::RESULT_GOT, self::RESULT_SKIP], true)) {
            return;
        }

        $this->closeCard($result);

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current'] = ['card' => $this->nextCardIndex(), 'letter' => false, 'bonus_said' => false];
        $this->turns = $turns;
        $this->save();
    }

    /**
     * Moves the card on screen into the turn's cards with $result and adds
     * its points.
     */
    private function closeCard(string $result): void
    {
        $turns = $this->turns;
        $last = array_key_last($turns);
        $current = $turns[$last]['current'];
        $card = $this->deck[$current['card']] ?? [];
        $points = 0;

        if ($result === self::RESULT_GOT) {
            $points = $current['letter'] ? self::POINTS_WITH_LETTER : self::POINTS_WORD;

            if (($card['bonus'] ?? []) !== [] && ! $current['bonus_said']) {
                $points += self::POINTS_BONUS;
            }
        }

        $turns[$last]['cards'][] = [
            'card' => $current['card'],
            'result' => $result,
            'letter' => $current['letter'],
            'bonus_said' => $current['bonus_said'],
            'points' => $points,
            'at' => now()->getTimestamp(),
        ];

        $this->turns = $turns;
        $this->score += $points;
    }

    /**
     * "Show first letter": the guesser's phone shows the word's first
     * letter and its length; a word guessed after it is worth half.
     */
    public function showLetter(User $user, int $seen): void
    {
        $this->markCurrent($user, $seen, 'letter');
    }

    /**
     * "I said a bonus word" — honest, from the explainer: no bonus point
     * for this card.
     */
    public function bonusSaid(User $user, int $seen): void
    {
        $this->markCurrent($user, $seen, 'bonus_said');
    }

    private function markCurrent(User $user, int $seen, string $flag): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || ! $this->explainer()->is($user) || count($turn['cards']) !== $seen) {
            return;
        }

        $turns = $this->turns;
        $turns[array_key_last($turns)]['current'][$flag] = true;
        $this->turns = $turns;
        $this->save();
    }

    /**
     * Called by the timer on both phones: the turn $index ends once its
     * time is up. The card left on screen counts as missed; after the last
     * turn the game is over.
     */
    public function timeUp(int $index): void
    {
        $turn = $this->currentTurn();

        if ($turn === null || $this->currentTurnIndex() !== $index || now()->getTimestamp() + self::CLOCK_SLACK_SECONDS < $turn['ends_at']) {
            return;
        }

        $this->closeTurn();
    }

    private function closeTurn(): void
    {
        $this->closeCard(self::RESULT_MISSED);

        $turns = $this->turns;
        unset($turns[array_key_last($turns)]['current']);
        $this->turns = $turns;

        if (count($turns) >= $this->plannedTurns()) {
            $this->finish();

            return;
        }

        $this->stage = self::STAGE_READY;
        $this->save();
    }

    public function finish(): void
    {
        if ($this->isFinished()) {
            return;
        }

        $this->forceFill(['stage' => self::STAGE_DONE, 'finished_at' => now()])->save();
    }

    /**
     * Calls off an invite before the friend joined. Either of the two can.
     */
    public function cancel(User $by): void
    {
        if (! $this->isWaiting()) {
            return;
        }

        $this->forceFill(['stage' => self::STAGE_CANCELLED, 'ended_by_id' => $by->id])->save();
    }

    /**
     * One of the two taps End: a game with at least one finished turn ends
     * with its score; one ended before that doesn't count.
     */
    public function endEarly(User $by): void
    {
        if ($this->isFinished() || $this->isCancelled() || $this->isWaiting()) {
            return;
        }

        $this->ended_by_id = $by->id;

        if ($this->isPlaying()) {
            $turns = $this->turns;
            unset($turns[array_key_last($turns)]['current']);
            $this->turns = $turns;
        }

        if (collect($this->turns ?? [])->sum(fn (array $turn) => count($turn['cards'])) === 0) {
            $this->forceFill(['stage' => self::STAGE_CANCELLED])->save();

            return;
        }

        $this->finish();
    }

    /**
     * Half points as shown: 9, 9½.
     */
    public static function formatPoints(int $halves): string
    {
        $whole = intdiv($halves, 2);

        return match (true) {
            $halves % 2 === 0 => (string) $whole,
            $whole === 0 => '½',
            default => $whole.'½',
        };
    }

    /**
     * The pair's best score in this game and length before this game —
     * null when they have never finished one.
     */
    public function previousBest(): ?int
    {
        $best = self::forPair($this->userA, $this->userB)
            ->where('game', $this->game)
            ->where('minutes', $this->minutes)
            ->where('stage', self::STAGE_DONE)
            ->whereKeyNot($this->id)
            ->when($this->finished_at, fn (Builder $query) => $query->where('finished_at', '<', $this->finished_at))
            ->max('score');

        return $best === null ? null : (int) $best;
    }

    public function isNewRecord(): bool
    {
        $best = $this->previousBest();

        return $this->isFinished() && $this->score > 0 && ($best === null || $this->score > $best);
    }

    /**
     * Every card played, with its word: what the result screen lists.
     *
     * @return list<array{word: string, result: string, explainer_id: int, points: int}>
     */
    public function playedCards(): array
    {
        return collect($this->turns ?? [])
            ->flatMap(fn (array $turn) => collect($turn['cards'])->map(fn (array $card) => [
                'word' => $this->deck[$card['card']]['word'] ?? '',
                'result' => $card['result'],
                'explainer_id' => $turn['explainer_id'],
                'points' => $card['points'],
            ]))
            ->values()
            ->all();
    }

    /**
     * Words guessed while $user explained.
     */
    public function guessedWhileExplaining(User $user): int
    {
        return collect($this->playedCards())->where('explainer_id', $user->id)->where('result', self::RESULT_GOT)->count();
    }

    /**
     * Words skipped or left when the time ran out — shown at the end only,
     * never saved anywhere.
     *
     * @return list<string>
     */
    public function missedWords(): array
    {
        return collect($this->playedCards())
            ->where('result', '!=', self::RESULT_GOT)
            ->pluck('word')
            ->unique()
            ->values()
            ->all();
    }
}
