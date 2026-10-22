<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One Pair Practice round: two friends, each on their own phone (side by
 * side or on a video call), ~12 minutes of vocabulary, grammar and speaking
 * cards. It waits until the invited friend joins. Roles swap on every card
 * — the speaker sees only the prompt and answers out loud, the judge sees
 * the answer and scores it.
 */
#[Fillable(['user_a_id', 'user_b_id', 'started_by_id', 'mission_code', 'day_number', 'days', 'meet_link', 'joined_at', 'ended_by_id', 'deck', 'results', 'stage', 'stage_ends_at', 'score_a', 'score_b', 'finished_at'])]
class PairPracticeSession extends Model
{
    use HasFactory;

    public const STAGE_WAITING = 'waiting';

    public const STAGE_VOCABULARY = 'vocabulary';

    public const STAGE_GRAMMAR = 'grammar';

    public const STAGE_SPEAKING = 'speaking';

    public const STAGE_DONE = 'done';

    /**
     * An invite called off before the friend joined, or a round ended too
     * early to count — kept (not deleted) so the other phone can say so.
     */
    public const STAGE_CANCELLED = 'cancelled';

    /**
     * Each stage's time box in seconds, in the order they are played.
     */
    public const STAGE_SECONDS = [
        self::STAGE_VOCABULARY => 180,
        self::STAGE_GRAMMAR => 180,
        self::STAGE_SPEAKING => 360,
    ];

    /**
     * How long the invited friend has to join before the invite expires.
     */
    public const INVITE_MINUTES = 10;

    /**
     * How long an unfinished round can be picked up again after a dropped
     * connection or a closed tab.
     */
    public const RESUME_HOURS = 2;

    /**
     * Most points one speaking answer can earn, however many target words
     * the asker heard — so speaking doesn't outweigh the other stages.
     */
    public const SPEAKING_MAX_POINTS = 2;

    /**
     * A round ended by hand with fewer answered cards than this doesn't
     * count: no history row, no score, and its day stays unpractised.
     */
    public const MIN_COUNTED_CARDS = 4;

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'deck' => 'array',
            'results' => 'array',
            'joined_at' => 'datetime',
            'stage_ends_at' => 'datetime',
            'finished_at' => 'datetime',
            'day_number' => 'integer',
            'score_a' => 'integer',
            'score_b' => 'integer',
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
     * Every session of this pair, whichever of the two started it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForPair(Builder $query, User $a, User $b): void
    {
        [$lowId, $highId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $query->where('user_a_id', $lowId)->where('user_b_id', $highId);
    }

    /**
     * Rounds that can still be picked up: unfinished, recent enough to
     * resume, and not an invite that expired before the friend joined.
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

    public function isFinished(): bool
    {
        return $this->stage === self::STAGE_DONE;
    }

    public function isCancelled(): bool
    {
        return $this->stage === self::STAGE_CANCELLED;
    }

    public function isWaiting(): bool
    {
        return $this->stage === self::STAGE_WAITING;
    }

    public function isExpired(): bool
    {
        return $this->isWaiting() && $this->created_at->lte(now()->subMinutes(self::INVITE_MINUTES));
    }

    /**
     * The invited friend joins and the first stage's clock starts. Only
     * the one who did not start the round can join, and only in time.
     */
    public function join(User $user): void
    {
        if (! $this->isWaiting() || $this->isExpired() || $user->id === $this->started_by_id || ! $this->isAccessibleBy($user)) {
            return;
        }

        $this->joined_at = now();
        $this->advanceStage();
    }

    /**
     * The two players in turn order — whoever started the round speaks first.
     *
     * @return array{0: User, 1: User}
     */
    public function players(): array
    {
        return [$this->startedBy, $this->partnerFor($this->startedBy)];
    }

    /**
     * Who answers the current card. Roles swap on every card across all
     * three stages, so the count of answered cards decides the turn — but
     * a sentence from a player's own Error Log is always theirs to answer.
     */
    public function speaker(): User
    {
        $players = $this->players();
        $owner = $this->currentCard()['owner_id'] ?? null;

        foreach ($players as $player) {
            if ($player->id === $owner) {
                return $player;
            }
        }

        return $players[count($this->results ?? []) % 2];
    }

    /**
     * Who holds the answer and scores the current card.
     */
    public function judge(): User
    {
        return $this->partnerFor($this->speaker());
    }

    /**
     * The days the round draws from, for a header: "M05 · Day 2", or the
     * chosen ones ("M01 · Days 1–3 + M02 · Day 1").
     */
    public function daysLabel(): string
    {
        return self::describeDays($this->days ?? [$this->mission_code.'-'.$this->day_number]);
    }

    /**
     * Day keys ("M01-2") as a short label, consecutive days joined into a
     * range: ["M01-1", "M01-2", "M01-3", "M02-2"] → "M01 · Days 1–3 + M02 · Day 2".
     *
     * @param  list<string>  $keys
     */
    public static function describeDays(array $keys): string
    {
        return collect($keys)
            ->map(fn (string $key) => explode('-', $key, 2))
            ->groupBy(0)
            ->map(function (Collection $days, string $code) {
                $numbers = $days->map(fn (array $parts) => (int) $parts[1])->unique()->sort()->values();
                $ranges = [];

                foreach ($numbers as $number) {
                    $last = array_key_last($ranges);

                    if ($last !== null && $ranges[$last][1] === $number - 1) {
                        $ranges[$last][1] = $number;
                    } else {
                        $ranges[] = [$number, $number];
                    }
                }

                $parts = collect($ranges)->map(fn (array $range) => $range[0] === $range[1] ? (string) $range[0] : $range[0].'–'.$range[1]);

                return $code.' · '.($numbers->count() === 1 ? 'Day ' : 'Days ').$parts->implode(', ');
            })
            ->implode(' + ');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentCard(): ?array
    {
        return $this->deck[$this->stage][$this->positionInStage()] ?? null;
    }

    public function positionInStage(): int
    {
        return collect($this->results ?? [])->where('stage', $this->stage)->count();
    }

    public function cardsInStage(): int
    {
        return count($this->deck[$this->stage] ?? []);
    }

    /**
     * Scores the current card for its speaker and moves on; the stage ends
     * early when its cards run out. $correct is null for speaking cards,
     * which earn one point per target word the asker heard ($heard).
     *
     * @param  list<string>  $heard
     */
    public function record(int $points, ?bool $correct, array $heard = []): void
    {
        if ($this->isFinished() || $this->currentCard() === null) {
            return;
        }

        $speaker = $this->speaker();
        $points = max(0, $points);

        $this->results = [...($this->results ?? []), [
            'stage' => $this->stage,
            'card' => $this->positionInStage(),
            'speaker_id' => $speaker->id,
            'points' => $points,
            'correct' => $correct,
            'heard' => $correct === null ? array_values($heard) : null,
            'at' => now()->getTimestamp(),
        ]];

        if ($speaker->id === $this->user_a_id) {
            $this->score_a += $points;
        } else {
            $this->score_b += $points;
        }

        if ($this->currentCard() === null) {
            $this->advanceStage();

            return;
        }

        $this->save();
    }

    /**
     * Opens the next stage that has cards (with a fresh time box), or ends
     * the round after speaking.
     */
    public function advanceStage(): void
    {
        $stages = array_keys(self::STAGE_SECONDS);
        $next = array_search($this->stage, $stages, true);
        $next = $next === false ? 0 : $next + 1;

        foreach (array_slice($stages, $next) as $stage) {
            if (($this->deck[$stage] ?? []) !== []) {
                $this->stage = $stage;
                $this->stage_ends_at = now()->addSeconds(self::STAGE_SECONDS[$stage]);
                $this->save();

                return;
            }
        }

        $this->finish();
    }

    /**
     * Ends the round and saves what each player got wrong for their own
     * review: words they could not say and target words they did not use
     * into My Words, sentences they could not fix into their Error Log.
     */
    public function finish(): void
    {
        if ($this->isFinished()) {
            return;
        }

        $this->stage = self::STAGE_DONE;
        $this->stage_ends_at = null;
        $this->finished_at = now();
        $this->save();

        foreach ([$this->userA, $this->userB] as $player) {
            foreach ($this->missedWordsFor($player) as $card) {
                $player->vocabularyWords()->firstOrCreate(
                    ['word' => $card['answer']],
                    [
                        'meaning' => $card['meaning'] ?? null,
                        'meaning_fa' => $card['prompt'],
                        'pos' => $card['pos'] ?? null,
                        'example' => $card['example'] ?? null,
                        'next_review_at' => now()->addDay(),
                    ],
                );
            }

            foreach ($this->unusedWordsFor($player) as $word) {
                $card = collect($this->deck[self::STAGE_VOCABULARY] ?? [])->first(fn (array $card) => mb_strtolower($card['answer']) === mb_strtolower($word));

                $player->vocabularyWords()->firstOrCreate(
                    ['word' => $word],
                    [
                        'meaning' => $card['meaning'] ?? null,
                        'meaning_fa' => $card['prompt'] ?? Mission::where('code', $this->mission_code)->first()?->persianMeaningFor($word),
                        'pos' => $card['pos'] ?? null,
                        'example' => $card['example'] ?? null,
                        'next_review_at' => now()->addDay(),
                    ],
                );
            }

            foreach ($this->missedSentencesFor($player) as $card) {
                $run = $player->missionRuns()->whereRelation('mission', 'code', $card['mission'] ?? $this->mission_code)->first();

                if ($run) {
                    ErrorLogItem::firstOrCreate(
                        ['mission_run_id' => $run->id, 'error' => $card['prompt']],
                        ['correction' => $card['answer']],
                    );
                }
            }
        }
    }

    /**
     * Calls off an invite before the friend joined. Either of the two can.
     */
    public function cancel(User $by): void
    {
        if (! $this->isWaiting()) {
            return;
        }

        $this->forceFill(['stage' => self::STAGE_CANCELLED, 'stage_ends_at' => null, 'ended_by_id' => $by->id])->save();
    }

    /**
     * One of the two taps End: the round finishes for both — or, with
     * fewer than MIN_COUNTED_CARDS answered, is dropped as too short.
     */
    public function endEarly(User $by): void
    {
        if ($this->isFinished() || $this->isCancelled() || $this->isWaiting()) {
            return;
        }

        $this->ended_by_id = $by->id;

        if (! $this->countsIfEndedNow()) {
            $this->forceFill(['stage' => self::STAGE_CANCELLED, 'stage_ends_at' => null])->save();

            return;
        }

        $this->finish();
    }

    public function countsIfEndedNow(): bool
    {
        return count($this->results ?? []) >= self::MIN_COUNTED_CARDS;
    }

    public function scoreFor(User $user): int
    {
        return $user->id === $this->user_a_id ? $this->score_a : $this->score_b;
    }

    /**
     * The grammar sentences $user had to fix and got wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function missedSentencesFor(User $user): array
    {
        return $this->missedCardsFor($user, self::STAGE_GRAMMAR);
    }

    /**
     * Speaking target words $user's partner did not tick while $user
     * answered, each once.
     *
     * @return list<string>
     */
    public function unusedWordsFor(User $user): array
    {
        return collect($this->results ?? [])
            ->filter(fn (array $result) => $result['stage'] === self::STAGE_SPEAKING
                && $result['speaker_id'] === $user->id
                && is_array($result['heard'] ?? null))
            ->flatMap(fn (array $result) => array_diff($this->deck[self::STAGE_SPEAKING][$result['card']]['words'] ?? [], $result['heard']))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The vocabulary cards $user had to say and got wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function missedWordsFor(User $user): array
    {
        return $this->missedCardsFor($user, self::STAGE_VOCABULARY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missedCardsFor(User $user, string $stage): array
    {
        return collect($this->results ?? [])
            ->filter(fn (array $result) => $result['stage'] === $stage
                && $result['speaker_id'] === $user->id
                && $result['correct'] === false)
            ->map(fn (array $result) => $this->deck[$stage][$result['card']] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
