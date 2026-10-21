<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One in-person Pair Practice round: two friends, one phone, ~12 minutes of
 * vocabulary, grammar and speaking cards. Roles swap on every card — the
 * speaker answers out loud, the judge sees the answer and scores it.
 */
#[Fillable(['user_a_id', 'user_b_id', 'started_by_id', 'mission_code', 'day_number', 'deck', 'results', 'stage', 'stage_ends_at', 'score_a', 'score_b', 'finished_at'])]
class PairPracticeSession extends Model
{
    use HasFactory;

    public const STAGE_VOCABULARY = 'vocabulary';

    public const STAGE_GRAMMAR = 'grammar';

    public const STAGE_SPEAKING = 'speaking';

    public const STAGE_DONE = 'done';

    /**
     * Each stage's time box in seconds, in the order they are played.
     */
    public const STAGE_SECONDS = [
        self::STAGE_VOCABULARY => 180,
        self::STAGE_GRAMMAR => 180,
        self::STAGE_SPEAKING => 360,
    ];

    protected function casts(): array
    {
        return [
            'deck' => 'array',
            'results' => 'array',
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
     * Every session of this pair, whichever of the two started it.
     *
     * @param  Builder<self>  $query
     */
    public function scopeForPair(Builder $query, User $a, User $b): void
    {
        [$lowId, $highId] = $a->id < $b->id ? [$a->id, $b->id] : [$b->id, $a->id];

        $query->where('user_a_id', $lowId)->where('user_b_id', $highId);
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
     * three stages, so the count of answered cards decides the turn.
     */
    public function speaker(): User
    {
        return $this->players()[count($this->results ?? []) % 2];
    }

    /**
     * Who holds the answer and scores the current card.
     */
    public function judge(): User
    {
        return $this->players()[(count($this->results ?? []) + 1) % 2];
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
     * which earn one point per target word the asker heard.
     */
    public function record(int $points, ?bool $correct): void
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
     * Ends the round and saves every word a player could not say into that
     * player's own My Words, so it comes back in their review.
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
        }
    }

    public function scoreFor(User $user): int
    {
        return $user->id === $this->user_a_id ? $this->score_a : $this->score_b;
    }

    /**
     * The vocabulary cards $user had to say and got wrong.
     *
     * @return list<array<string, mixed>>
     */
    public function missedWordsFor(User $user): array
    {
        return collect($this->results ?? [])
            ->filter(fn (array $result) => $result['stage'] === self::STAGE_VOCABULARY
                && $result['speaker_id'] === $user->id
                && $result['correct'] === false)
            ->map(fn (array $result) => $this->deck[self::STAGE_VOCABULARY][$result['card']] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
