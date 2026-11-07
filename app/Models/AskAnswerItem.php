<?php

namespace App\Models;

use Database\Factories\AskAnswerItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One situation of the Ask & Answer game: a question the learner asks and
 * the answer the friend gives, each written at three levels (see LEVELS).
 * The Persian line says what to ask or say (the intent, never a word-for-word
 * translation), a fainter Persian line suggests something extra, and the
 * English is what the partner sees to help. Harder levels differ in
 * structure, length and detail — never in harder words.
 *
 * Each level is: fa, fa_extra, en, en_extra, alt (other accepted sentences),
 * wrong (known wrong versions: text + why in Persian).
 */
#[Fillable(['slug', 'mission_number', 'day', 'kind', 'situation', 'context_fa', 'questions', 'answers'])]
class AskAnswerItem extends Model
{
    /** @use HasFactory<AskAnswerItemFactory> */
    use HasFactory;

    /** A question, then its answer. */
    public const KIND_SIMPLE = 'simple';

    /** A question about something the friend just said (shown as the context line). */
    public const KIND_FOLLOWUP = 'followup';

    /** @var list<int> */
    public const LEVELS = [1, 2, 3];

    public const SIDE_QUESTION = 'questions';

    public const SIDE_ANSWER = 'answers';

    protected function casts(): array
    {
        return [
            'mission_number' => 'integer',
            'day' => 'integer',
            'questions' => 'array',
            'answers' => 'array',
        ];
    }

    /**
     * One side of the item at a level; a level the item doesn't have falls
     * back to the nearest lower one.
     *
     * @param  self::SIDE_*  $side
     * @return array{fa: string, fa_extra: ?string, en: string, en_extra: ?string, alt: list<string>, wrong: list<array{text: string, why: string}>}
     */
    public function level(string $side, int $level): array
    {
        $levels = $this->{$side};

        for ($try = min(max($level, 1), 3); $try >= 1; $try--) {
            if (isset($levels[$try])) {
                return $levels[$try];
            }
        }

        return $levels[1] ?? ['fa' => '', 'fa_extra' => null, 'en' => '', 'en_extra' => null, 'alt' => [], 'wrong' => []];
    }

    /**
     * What a game keeps in its deck: the whole item, so a game never reads
     * the table again and a later edit never changes a game in progress.
     *
     * @return array{id: int, key: string, kind: string, situation: string, context_fa: ?string, source: string, questions: array<int, array<string, mixed>>, answers: array<int, array<string, mixed>>}
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'key' => 'qa:'.$this->slug,
            'kind' => $this->kind,
            'situation' => $this->situation,
            'context_fa' => $this->context_fa,
            'source' => 'M'.str_pad((string) $this->mission_number, 2, '0', STR_PAD_LEFT).' · Day '.$this->day,
            'questions' => $this->questions,
            'answers' => $this->answers,
        ];
    }
}
