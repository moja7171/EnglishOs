<?php

namespace App\Models;

use App\Services\StreakRing;
use Database\Factories\AiPracticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One practice with Tara, the AI classmate at the learner's level: a Pair
 * Practice round's cards and game, or just one game. Turn about, with no
 * judging: the learner answers a card and she checks it, the next card she
 * answers and the learner listens. It is the learner's own practice: it
 * counts for their day, never for a pair's streak, the board, badges or
 * records.
 */
#[Fillable(['learner_id', 'kind', 'days', 'deck', 'stage', 'position', 'current', 'answered', 'score', 'finished_at'])]
class AiPractice extends Model
{
    /** @use HasFactory<AiPracticeFactory> */
    use HasFactory;

    /** The three card parts of a round, then its game. */
    public const KIND_PRACTICE = 'practice';

    public const STAGE_VOCABULARY = 'vocabulary';

    public const STAGE_GRAMMAR = 'grammar';

    public const STAGE_SPEAKING = 'speaking';

    public const STAGE_GAME = 'game';

    public const STAGE_DONE = 'done';

    /** @var list<string> */
    public const CARD_STAGES = [self::STAGE_VOCABULARY, self::STAGE_GRAMMAR, self::STAGE_SPEAKING];

    /** The games Tara can play so far. */
    public const GAMES = [PairGame::GAME_WHO, PairGame::GAME_RATHER, PairGame::GAME_MINUTE];

    /** Questions about one secret: fewer than with a friend, each one is an AI call. */
    public const MAX_QUESTIONS = 8;

    /** A practice ended with fewer answers than this doesn't count for the day. */
    public const MIN_COUNTED_ANSWERS = 3;

    public const RESUME_HOURS = 2;

    /** How close a spoken sentence must be to the right one, in percent. */
    private const CLOSE_ENOUGH_PERCENT = 80;

    /** @var array<string, mixed> */
    protected $attributes = ['position' => 0, 'answered' => 0, 'score' => 0];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'deck' => 'array',
            'current' => 'array',
            'position' => 'integer',
            'answered' => 'integer',
            'score' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    /**
     * Practices that can still be picked up.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('finished_at')->where('created_at', '>=', now()->subHours(self::RESUME_HOURS));
    }

    public function isFinished(): bool
    {
        return $this->stage === self::STAGE_DONE;
    }

    /**
     * The game in this practice, if any.
     */
    public function game(): ?string
    {
        return $this->deck['game']['game'] ?? null;
    }

    public function isGameStage(): bool
    {
        return $this->stage === self::STAGE_GAME;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(?string $stage = null): array
    {
        $stage ??= $this->stage;

        return $stage === self::STAGE_GAME ? ($this->deck['game']['cards'] ?? []) : ($this->deck[$stage] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentCard(): ?array
    {
        return $this->cards()[$this->position] ?? null;
    }

    /**
     * The parts this practice has, in order.
     *
     * @return list<string>
     */
    public function stages(): array
    {
        $stages = array_values(array_filter(self::CARD_STAGES, fn (string $stage) => $this->cards($stage) !== []));

        return $this->cards(self::STAGE_GAME) !== [] ? [...$stages, self::STAGE_GAME] : $stages;
    }

    /**
     * Whose card this is: every other one is the AI's, except a card of
     * the learner's own Error Log and one with no answer for the AI to say.
     */
    public function learnerAnswers(): bool
    {
        $card = $this->currentCard();

        return $this->position % 2 === 0 || isset($card['owner_id']) || blank($card['answer'] ?? null);
    }

    /**
     * Who am I: the AI holds the secret on even turns, the learner on odd.
     */
    public function aiHoldsSecret(): bool
    {
        return $this->position % 2 === 0;
    }

    /**
     * In the games played turn about the learner goes first, on even cards.
     */
    public function isLearnersGameTurn(): bool
    {
        return $this->position % 2 === 0;
    }

    /**
     * Which of the words are in the text, by plain matching with no AI
     * call: a word also counts with a common ending (cooks, cooked,
     * cooking), and speech-to-text capitals and commas don't matter.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public static function wordsIn(string $text, array $words): array
    {
        $plainText = ' '.self::plain($text).' ';

        return array_values(array_filter($words, function (string $word) use ($plainText) {
            $plainWord = self::plain($word);

            return $plainWord !== '' && preg_match('/ '.preg_quote($plainWord, '/').'(s|es|d|ed|ing)? /u', $plainText) === 1;
        }));
    }

    /**
     * Whether what the learner said is the card's answer. Speech-to-text
     * is not exact, so a sentence only has to be close, and a word or
     * phrase only has to be in what they said.
     */
    public static function saidCorrectly(string $heard, string $answer): bool
    {
        [$heard, $answer] = [self::plain($heard), self::plain($answer)];

        if ($heard === '' || $answer === '') {
            return false;
        }

        if (str_contains(" {$heard} ", " {$answer} ")) {
            return true;
        }

        similar_text($heard, $answer, $percent);

        return $percent >= self::CLOSE_ENOUGH_PERCENT;
    }

    private static function plain(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($text));

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }

    /**
     * The learner's answer to the card on screen, once. $correct is null
     * for a free answer, which always counts; a word they could not say
     * is kept for My Words.
     */
    public function recordAnswer(string $heard, ?bool $correct, ?string $feedback = null): void
    {
        $this->current = ['heard' => $heard, 'correct' => $correct, 'feedback' => $feedback];
        $this->answered++;
        $this->score += $correct === false ? 0 : 1;

        if ($correct === false && $this->stage === self::STAGE_VOCABULARY) {
            $this->deck = [...$this->deck, 'missed_words' => [...($this->deck['missed_words'] ?? []), $this->currentCard()]];
        }

        $this->save();
    }

    /**
     * On to the next card, the next part, or the end.
     */
    public function next(): void
    {
        $this->current = null;
        $this->position++;

        if ($this->currentCard() === null) {
            $stages = $this->stages();
            $following = $stages[array_search($this->stage, $stages, true) + 1] ?? null;

            if ($following === null) {
                $this->finish();

                return;
            }

            $this->stage = $following;
            $this->position = 0;
        }

        $this->save();
    }

    /**
     * Ends the practice: a slice of the learner's own day, and the words
     * they could not say wait for them in My Words.
     */
    public function finish(): void
    {
        if ($this->isFinished()) {
            return;
        }

        $this->stage = self::STAGE_DONE;
        $this->current = null;
        $this->finished_at = now();
        $this->save();

        if ($this->answered >= self::MIN_COUNTED_ANSWERS) {
            app(StreakRing::class)->record($this->learner, 'ai-practice');
        }

        foreach ($this->deck['missed_words'] ?? [] as $card) {
            $this->learner->vocabularyWords()->firstOrCreate(
                ['word' => $card['answer']],
                ['meaning' => $card['meaning'] ?? null, 'meaning_fa' => $card['prompt'], 'pos' => $card['pos'] ?? null, 'example' => $card['example'] ?? null, 'next_review_at' => now()->addDay()],
            );
        }
    }
}
