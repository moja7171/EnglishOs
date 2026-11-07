<?php

namespace App\Models;

use App\Services\FeedbackNotebook;
use App\Services\StreakRing;
use App\Services\TeddyLevel;
use Database\Factories\AiPracticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One practice with Teddy, the AI classmate at the learner's level: a Pair
 * Practice round's cards and game, or just one game. Turn about, with no
 * judging: the learner answers a card and he checks it, the next card he
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

    /** The games Teddy can play so far. */
    public const GAMES = [PairGame::GAME_WHO, PairGame::GAME_RATHER, PairGame::GAME_MINUTE, PairGame::GAME_STORY, PairGame::GAME_TRUTHS, PairGame::GAME_TABOO];

    /** Questions about one secret: fewer than with a friend, each one is an AI call. */
    public const MAX_QUESTIONS = 8;

    /** Two Truths: questions the learner may ask Teddy before picking his lie. */
    public const TRUTHS_QUESTIONS = 2;

    /** Taboo: tries at one word, for whichever of the two is guessing. */
    public const TABOO_TRIES = 3;

    /** Yes/no questions Teddy can fall back on in Who am I when the AI keeps repeating itself. */
    public const FALLBACK_QUESTIONS = [
        'Are you alive?', 'Are you big?', 'Can people touch you?', 'Can you find me at home?', 'Do people use you every day?',
        'Do people eat you?', 'Are you expensive?', 'Are you made by people?', 'Can you move?', 'Are you red?',
    ];

    /** A practice ended with fewer answers than this doesn't count for the day. */
    public const MIN_COUNTED_ANSWERS = 3;

    public const RESUME_HOURS = 2;

    /** How close a spoken sentence must be to the right one, in percent. */
    private const CLOSE_ENOUGH_PERCENT = 80;

    /** A single word is only taken for another when it is this close: house is not horse. */
    private const CLOSE_ENOUGH_FOR_A_WORD = 90;

    /** The longest sentence of Teddy's that goes into the learner's notebook when they miss its slip. */
    private const NOTEBOOK_LINE_LIMIT = 160;

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

            return $plainWord !== '' && preg_match('/ '.self::formsPattern($plainWord).' /u', $plainText) === 1;
        }));
    }

    /**
     * The word and its other forms as a regex: a common ending (cooks,
     * cooked, cooking), the dropped e (making), the changed y (studied)
     * and the doubled last letter (running). A phrase only takes the
     * common endings.
     */
    private static function formsPattern(string $plainWord): string
    {
        $base = preg_quote($plainWord, '/');
        $forms = [$base.'(?:s|es|d|ed|ing)?'];

        if (! str_contains($plainWord, ' ') && mb_strlen($plainWord) >= 3) {
            $stem = preg_quote(mb_substr($plainWord, 0, -1), '/');
            $last = mb_substr($plainWord, -1);

            if ($last === 'e') {
                $forms[] = $stem.'ing';
            } elseif ($last === 'y' && ! str_contains('aeiou', mb_substr($plainWord, -2, 1))) {
                $forms[] = $stem.'(?:ies|ied)';
            } elseif (! str_contains('aeiouwxy', $last)) {
                $forms[] = $base.preg_quote($last, '/').'(?:ed|ing)';
            }
        }

        return '(?:'.implode('|', $forms).')';
    }

    /**
     * Whether the text names the word, with no AI call: said exactly or
     * in another form, or, for what was spoken aloud, close enough for
     * speech-to-text. What Teddy typed is never "close enough".
     */
    public static function namesWord(string $text, string $word, bool $heardAloud = true): bool
    {
        return self::wordsIn($text, [$word]) !== [] || ($heardAloud && self::saidCorrectly($text, $word, self::CLOSE_ENOUGH_FOR_A_WORD));
    }

    /**
     * Whether what the learner said is the card's answer. Speech-to-text
     * is not exact, so a sentence only has to be close, and a word or
     * phrase only has to be in what they said.
     */
    public static function saidCorrectly(string $heard, string $answer, int $closeEnough = self::CLOSE_ENOUGH_PERCENT): bool
    {
        [$heard, $answer] = [self::plain($heard), self::plain($answer)];

        if ($heard === '' || $answer === '') {
            return false;
        }

        if (str_contains(" {$heard} ", " {$answer} ")) {
            return true;
        }

        similar_text($heard, $answer, $percent);

        return $percent >= $closeEnough;
    }

    /**
     * A word card's hints, one more on each tap: its English meaning, then
     * its first letter and an example with the word blanked out.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    public static function wordHints(array $card): array
    {
        $word = (string) ($card['answer'] ?? '');
        $hints = [];

        if (filled($card['meaning'] ?? null)) {
            $hints[] = self::blankedOut($card['meaning'], $word);
        }

        $starts = 'Starts with “'.mb_substr($word, 0, 1).'” · '.mb_strlen(str_replace(' ', '', $word)).' letters';
        $hints[] = filled($card['example'] ?? null) ? $starts.' · '.self::blankedOut($card['example'], $word) : $starts;

        return $word === '' ? [] : $hints;
    }

    /**
     * The text with the word, and the same word with an ending, replaced
     * by a blank.
     */
    public static function blankedOut(string $text, string $word): string
    {
        return (string) preg_replace('/\b'.self::formsPattern(mb_strtolower($word)).'\b/iu', '_____', $text);
    }

    /**
     * The text cut into its sentences.
     *
     * @return list<string>
     */
    public static function sentencesOf(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [])));
    }

    /**
     * Whether two yes/no questions ask the same thing: the same words once
     * capitals and punctuation are gone, in any order, or nearly the same
     * text.
     */
    public static function sameQuestion(string $first, string $second): bool
    {
        [$first, $second] = [self::plain($first), self::plain($second)];

        if ($first === '' || $second === '') {
            return false;
        }

        $words = fn (string $text) => collect(explode(' ', $text))->sort()->values()->all();

        if ($first === $second || $words($first) === $words($second)) {
            return true;
        }

        similar_text($first, $second, $percent);

        return $percent >= 88;
    }

    private static function plain(string $text): string
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', mb_strtolower($text));

        return trim(preg_replace('/\s+/', ' ', (string) $text));
    }

    /**
     * All the cards of the practice, in every part.
     */
    public function totalCards(): int
    {
        return collect([...self::CARD_STAGES, self::STAGE_GAME])->sum(fn (string $stage) => count($this->cards($stage)));
    }

    /**
     * How many cards the learner got to: all of them for a practice played
     * to its end, the ones up to where they left for one ended early (kept
     * when it ended).
     */
    public function cardsReached(): int
    {
        if ($this->finished_at !== null) {
            return (int) ($this->deck['cards_reached'] ?? $this->totalCards());
        }

        $before = 0;

        foreach ($this->stages() as $stage) {
            if ($stage === $this->stage) {
                break;
            }

            $before += count($this->cards($stage));
        }

        return $before + min(count($this->cards()), $this->position + ($this->current === null ? 0 : 1));
    }

    /**
     * The most points the first $reached cards could give. A card is
     * worth a point, except in Just a Minute, where the target words and
     * the questions count too.
     */
    public function pointsPossible(int $reached): int
    {
        $worths = [];

        foreach ($this->stages() as $stage) {
            foreach ($this->cards($stage) as $position => $card) {
                $worths[] = $stage === self::STAGE_GAME && $this->game() === PairGame::GAME_MINUTE
                    ? count($card['words'] ?? []) + ($position % 2 === 1 ? 2 : 0)
                    : 1;
            }
        }

        return max(1, array_sum(array_slice($worths, 0, $reached)));
    }

    /**
     * The share of the possible points the learner won on the cards they
     * got to, 0 to 1 (see App\Services\TeddyLevel).
     */
    public function scoreShare(): float
    {
        return min(1, $this->score / $this->pointsPossible($this->cardsReached()));
    }

    /**
     * How well Teddy plays in this practice (see App\Services\TeddyLevel).
     */
    public function level(): string
    {
        return $this->deck['teddy_level'] ?? TeddyLevel::NORMAL;
    }

    /**
     * Whether Teddy slips on this turn: planned once when the practice
     * began, so it stays the same when the page is shown again.
     */
    public function teddySlips(): bool
    {
        return in_array($this->position, $this->deck['slips'][$this->stage] ?? [], true);
    }

    /**
     * What Teddy says on a card of his own: the deck's answer, or a
     * believable wrong one on a turn he slips on. A word card gets another
     * card's word, a grammar card the wrong sentence as it stands, and a
     * speaking card the slip the AI wrote for it (kept in `current`).
     */
    public function teddyCardLine(): string
    {
        $card = $this->currentCard() ?? [];
        $answer = (string) ($card['answer'] ?? '');

        if (! $this->teddySlips()) {
            return $answer;
        }

        $wrong = match ($this->stage) {
            self::STAGE_VOCABULARY => $this->otherCardsAnswer($answer),
            self::STAGE_GRAMMAR => (string) ($card['prompt'] ?? ''),
            default => (string) ($this->current['says'] ?? ''),
        };

        return filled($wrong) && $wrong !== $answer ? $wrong : $answer;
    }

    /**
     * Whether what Teddy says on this card is wrong.
     */
    public function teddyIsWrong(): bool
    {
        return $this->teddyCardLine() !== (string) ($this->currentCard()['answer'] ?? '');
    }

    /**
     * Another word card's word, the same one every time for this card.
     */
    private function otherCardsAnswer(string $answer): string
    {
        $others = collect($this->cards())->pluck('answer')->filter(fn ($other) => filled($other) && $other !== $answer)->values();

        return (string) ($others->isEmpty() ? '' : $others[$this->position % $others->count()]);
    }

    /**
     * The learner's verdict on Teddy's turn, once: a point for judging him
     * right, whether he was right or slipped.
     *
     * @param  'right'|'mistake'  $verdict
     */
    public function recordJudgement(string $verdict): void
    {
        $wrong = $this->teddyIsWrong();
        $caught = ($verdict === 'mistake') === $wrong;

        if ($wrong) {
            $this->logSlip($this->teddyCardLine(), (string) ($this->currentCard()['answer'] ?? ''), $verdict === 'mistake', $this->stage === self::STAGE_VOCABULARY ? 'answer' : 'sentence');
        }

        $this->current = [...($this->current ?? []), 'judged' => $verdict, 'teddy_wrong' => $wrong, 'says' => $this->teddyCardLine(), 'right_call' => $caught];
        $this->answered++;
        $this->score += $caught ? 1 : 0;
        $this->save();
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

        if ($correct !== null) {
            $tally = $this->deck['tally'][$this->stage] ?? ['right' => 0, 'total' => 0];
            $this->deck = [...$this->deck, 'tally' => [...($this->deck['tally'] ?? []), $this->stage => ['right' => $tally['right'] + ($correct ? 1 : 0), 'total' => $tally['total'] + 1]]];
        }

        if ($correct === false && $this->stage === self::STAGE_VOCABULARY) {
            $this->deck = [...$this->deck, 'missed_words' => [...($this->deck['missed_words'] ?? []), $this->currentCard()]];
            $this->keepMissedWord($this->currentCard());
        }

        $this->save();
    }

    /**
     * A slip of Teddy's the learner had the chance to catch, kept for the
     * end of the practice and the week's count. One they missed that is a
     * whole sentence goes into the notebook at once, to be spotted again
     * in Review. Saved with the model, not here.
     *
     * The part of the practice it happened in is kept too, to see where he
     * slips.
     *
     * @param  'sentence'|'answer'  $kind
     */
    public function logSlip(string $said, string $fix, bool $caught, string $kind = 'sentence'): void
    {
        $this->deck = [...$this->deck, 'slip_log' => [...($this->deck['slip_log'] ?? []), ['said' => $said, 'fix' => $fix, 'caught' => $caught, 'kind' => $kind, 'stage' => $this->stage]]];

        if (! $caught && $kind === 'sentence' && $said !== '' && mb_strlen($said) <= self::NOTEBOOK_LINE_LIMIT) {
            app(FeedbackNotebook::class)->recordCheck($this->learner, $said, $fix, null, 'games');
        }
    }

    /**
     * The slips of Teddy's in this practice, and how many the learner caught.
     *
     * @return array{total: int, caught: int}
     */
    public function slipCount(): array
    {
        $log = collect($this->deck['slip_log'] ?? []);

        return ['total' => $log->count(), 'caught' => $log->where('caught', true)->count()];
    }

    /**
     * A word the learner could not say waits for them in My Words, at once:
     * leaving the practice early loses nothing.
     *
     * @param  array<string, mixed>  $card
     */
    private function keepMissedWord(array $card): void
    {
        $this->learner->vocabularyWords()->firstOrCreate(
            ['word' => $card['answer']],
            ['meaning' => $card['meaning'] ?? null, 'meaning_fa' => ($card['prompt_lang'] ?? 'fa') === 'fa' ? $card['prompt'] : null, 'pos' => $card['pos'] ?? null, 'example' => $card['example'] ?? null, 'next_review_at' => now()->addDay()],
        );
    }

    /**
     * Once the learner has answered enough, the practice is a slice of
     * their day, even if they never reach the end.
     */
    public function creditTheDay(): void
    {
        if ($this->isFinished() || $this->answered < self::MIN_COUNTED_ANSWERS || ($this->deck['credited'] ?? false)) {
            return;
        }

        app(StreakRing::class)->record($this->learner, 'ai-practice');
        $this->deck = [...$this->deck, 'credited' => true];
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
     * Ends the practice: a slice of the learner's own day (if not already
     * counted on the way), and where they got to is kept, so leaving early
     * does not look like a bad game (see App\Services\TeddyLevel).
     */
    public function finish(): void
    {
        if ($this->isFinished()) {
            return;
        }

        $this->creditTheDay();
        $this->deck = [...$this->deck, 'cards_reached' => $this->cardsReached()];
        $this->stage = self::STAGE_DONE;
        $this->current = null;
        $this->finished_at = now();
        $this->save();
    }
}
