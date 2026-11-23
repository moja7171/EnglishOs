<?php

namespace App\Services;

use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabProgress;
use Illuminate\Support\Collection;

/**
 * The Vocab in Use course's own review: a card is one sentence of the
 * book, asked at one of four ladder steps — See (pick the meaning), Gap
 * (type the missing word), Order (put the words in order) and Recall
 * (type the whole sentence) — and checked leniently: small slips are right,
 * only a changed meaning is wrong. Separate from the Review tab and the
 * Notebook on purpose; see VocabProgress for the schedule.
 */
class VocabReview
{
    /** Cards a day before the "20 more" button. */
    public const DAILY_CAP = 40;

    public const MORE = 20;

    /** A sentence longer than this many words is put in order in two parts. */
    public const ORDER_PART_WORDS = 12;

    public function reviewedToday(User $user): int
    {
        return VocabProgress::query()
            ->where('user_id', $user->id)
            ->where('last_reviewed_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * How many more cards today's cap lets through before "20 more".
     */
    public function allowanceToday(User $user): int
    {
        return max(0, self::DAILY_CAP - $this->reviewedToday($user));
    }

    /**
     * The due cards, the ones waiting longest first.
     *
     * @param  list<int>  $except  Cards already in today's queue.
     * @return Collection<int, VocabProgress>
     */
    public function due(User $user, int $limit, array $except = []): Collection
    {
        return VocabProgress::query()
            ->where('user_id', $user->id)
            ->due()
            ->whereNotIn('id', $except)
            ->orderBy('next_review_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, VocabItem>
     */
    public function savedItems(VocabProgress $card): Collection
    {
        return $card->text->items->whereIn('id', $card->saved_item_ids)->sortBy('start')->values();
    }

    /**
     * The card's sentence in the book.
     *
     * @return array{text: string, start: int, end: int, audio_start: float|null, audio_end: float|null, fa: string|null}
     */
    public function sentence(VocabProgress $card): array
    {
        $sentence = $card->text->sentences[$card->sentence_index] ?? [];

        return [
            'text' => (string) ($sentence['text'] ?? ''),
            'start' => (int) ($sentence['start'] ?? 0),
            'end' => (int) ($sentence['end'] ?? 0),
            'audio_start' => $sentence['audio_start'] ?? null,
            'audio_end' => $sentence['audio_end'] ?? null,
            'fa' => $sentence['fa'] ?? null,
        ];
    }

    /**
     * The step to ask: the card's own ladder step, except that See needs a
     * meaning from the book to pick, so a sentence whose saved words have
     * none starts at Gap.
     */
    public function stepFor(VocabProgress $card): int
    {
        if ($card->ladder_step === 1 && $this->savedItems($card)->filter(fn (VocabItem $item): bool => $this->meaning($item) !== null)->isEmpty()) {
            return 2;
        }

        return $card->ladder_step;
    }

    /**
     * What a word means, as the See step shows it: the Persian meaning when
     * the course has one, otherwise the book's own English explanation.
     */
    public function meaning(VocabItem $item): ?string
    {
        return $item->phrase_fa ?: $item->gloss;
    }

    /**
     * See: the meaning of one saved word and two other meanings of the
     * course (in the same language) to pick it from.
     *
     * @return array{item: VocabItem, answer: string, options: list<string>}
     */
    public function seeQuestion(VocabProgress $card): array
    {
        $item = $this->savedItems($card)->filter(fn (VocabItem $saved): bool => $this->meaning($saved) !== null)->random();
        $answer = (string) $this->meaning($item);
        $column = $item->phrase_fa ? 'phrase_fa' : 'gloss';

        $others = VocabItem::query()
            ->whereNotNull($column)
            ->where($column, '!=', $answer)
            ->inRandomOrder()
            ->limit(30)
            ->pluck($column)
            ->unique()
            ->take(2)
            ->all();

        return [
            'item' => $item,
            'answer' => $answer,
            'options' => collect([$answer, ...$others])->shuffle()->values()->all(),
        ];
    }

    /**
     * Gap: the sentence with its first saved word taken out.
     *
     * @return array{before: string, after: string, answer: string, item: VocabItem}
     */
    public function gapQuestion(VocabProgress $card): array
    {
        $item = $this->savedItems($card)->first();
        $sentence = $this->sentence($card);
        $body = $card->text->body;

        return [
            'before' => mb_substr($body, $sentence['start'], $item->start - $sentence['start']),
            'after' => mb_substr($body, $item->end, $sentence['end'] - $item->end),
            'answer' => mb_substr($body, $item->start, $item->end - $item->start),
            'item' => $item,
        ];
    }

    /**
     * Order: the sentence's words, in one part or two when it is long.
     *
     * @return list<list<string>>
     */
    public function orderParts(string $sentence): array
    {
        $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) <= self::ORDER_PART_WORDS) {
            return [$words];
        }

        $half = (int) ceil(count($words) / 2);

        return [array_slice($words, 0, $half), array_slice($words, $half)];
    }

    /**
     * The words of a part in a different order than the book's.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    public function shuffled(array $words): array
    {
        if (count(array_unique($words)) < 2) {
            return $words;
        }

        for ($try = 0; $try < 10; $try++) {
            $shuffled = $words;
            shuffle($shuffled);

            if ($shuffled !== $words) {
                return $shuffled;
            }
        }

        return array_reverse($words);
    }

    /**
     * Gap: the word typed, with one typo allowed in a longer word.
     */
    public function checkWord(string $typed, string $expected): bool
    {
        $typed = $this->normalise($typed);
        $expected = $this->normalise($expected);

        if ($typed === '') {
            return false;
        }

        return $typed === $expected || $this->sameWord($typed, $expected);
    }

    /**
     * Recall: the sentence typed. Right when it is the book's sentence
     * (case and punctuation do not matter); "almost" when a small slip
     * (a missing little word, a typo, "Mum's" for "Mum is") leaves the
     * saved words and the meaning as they were; wrong otherwise.
     *
     * @param  list<string>  $required  The saved words and phrases, which must all be there.
     * @return array{verdict: string, missing: list<string>}
     */
    public function checkSentence(string $typed, string $expected, array $required = []): array
    {
        $typedTokens = $this->tokens($typed);
        $expectedTokens = $this->tokens($expected);

        if ($typedTokens === $expectedTokens) {
            return ['verdict' => 'right', 'missing' => []];
        }

        $missing = $this->absent($expectedTokens, $typedTokens);
        $extra = $this->absent($typedTokens, $expectedTokens);
        $slack = max(1, (int) floor(count($expectedTokens) * 0.2));

        $requiredPresent = collect($required)->every(fn (string $phrase): bool => $this->absent($this->tokens($phrase), $typedTokens) === []);

        if ($typedTokens !== [] && $requiredPresent && count($missing) <= $slack && count($extra) <= $slack) {
            return ['verdict' => 'almost', 'missing' => $missing];
        }

        return ['verdict' => 'wrong', 'missing' => $missing];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        $normalised = $this->normalise($text);

        return $normalised === '' ? [] : explode(' ', $normalised);
    }

    private function normalise(string $text): string
    {
        $text = mb_strtolower(str_replace(['’', '‘', '`'], "'", $text));
        $text = preg_replace("/[^\p{L}\p{N}' ]+/u", ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * The words of $from that $in does not have, matching a typo in a longer word.
     *
     * @param  list<string>  $from
     * @param  list<string>  $in
     * @return list<string>
     */
    private function absent(array $from, array $in): array
    {
        $pool = $in;
        $absent = [];

        foreach ($from as $word) {
            $found = array_search($word, $pool, true);

            if ($found === false) {
                $found = collect($pool)->search(fn (string $candidate): bool => $this->sameWord($word, $candidate));
            }

            if ($found === false) {
                $absent[] = $word;
            } else {
                unset($pool[$found]);
            }
        }

        return $absent;
    }

    private function sameWord(string $a, string $b): bool
    {
        return $a === $b || (min(mb_strlen($a), mb_strlen($b)) >= 5 && levenshtein($a, $b) <= 1);
    }
}
