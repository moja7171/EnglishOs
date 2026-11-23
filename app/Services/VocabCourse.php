<?php

namespace App\Services;

use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabularyWord;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Vocab in Use course (units 5-94 of the Cambridge book): the lesson
 * list, how far a learner got, and the words they add for review. An added
 * word is a VocabularyWord of source "vocab": it is reviewed in the Review
 * tab on its own path, and reading a lesson and reviewing its words count
 * for the daily ring and for friends like anything else (see StreakRing).
 */
class VocabCourse
{
    public const KEY = 'vocab';

    public const NAME = 'Vocab in Use';

    public const BLURB = 'The new words of the Cambridge book, lesson by lesson';

    /** A word reviewed up to this level counts as strong (see VocabularyWord::strengthLevel()). */
    private const STRONG_FROM_LEVEL = 4;

    public function __construct(private StreakRing $ring) {}

    public function totalUnits(): int
    {
        return VocabUnit::query()->count();
    }

    public function openedUnits(User $user): int
    {
        return VocabUnitRun::query()->where('user_id', $user->id)->count();
    }

    /**
     * Added words whose review time has come (the Vocab in Use path in Review).
     */
    public function dueCards(User $user): int
    {
        return $this->words($user)->where('next_review_at', '<=', now())->count();
    }

    /**
     * The 90 lessons grouped by the book's 16 sections, each with how far this
     * learner is: not opened, in progress (words added of the lesson's words)
     * or done. A lesson whose texts are not built yet cannot be opened.
     *
     * @return list<array{number: int, name: string, units: list<array{number: int, title: string, ready: bool, state: string, added: int, total: int, url: string|null}>}>
     */
    public function sections(User $user): array
    {
        $runs = VocabUnitRun::query()->where('user_id', $user->id)->get()->keyBy('vocab_unit_id');

        $totals = VocabItem::query()
            ->join('vocab_texts', 'vocab_texts.id', '=', 'vocab_items.vocab_text_id')
            ->selectRaw('vocab_texts.vocab_unit_id as unit_id, count(*) as total')
            ->groupBy('vocab_texts.vocab_unit_id')
            ->pluck('total', 'unit_id');

        $added = $this->words($user)
            ->join('vocab_items', 'vocab_items.id', '=', 'vocabulary_words.vocab_item_id')
            ->join('vocab_texts', 'vocab_texts.id', '=', 'vocab_items.vocab_text_id')
            ->selectRaw('vocab_texts.vocab_unit_id as unit_id, count(*) as added')
            ->groupBy('vocab_texts.vocab_unit_id')
            ->pluck('added', 'unit_id');

        return VocabUnit::query()
            ->withExists('texts')
            ->orderBy('number')
            ->get()
            ->groupBy('book_section')
            ->map(fn ($units, $section): array => [
                'number' => (int) $section,
                'name' => $units->first()->book_section_name,
                'units' => $units->map(function (VocabUnit $unit) use ($runs, $totals, $added): array {
                    $run = $runs->get($unit->id);

                    return [
                        'number' => $unit->number,
                        'title' => $unit->title,
                        'ready' => (bool) $unit->texts_exists,
                        'state' => $run === null ? 'new' : ($run->finished_at ? 'done' : 'open'),
                        'added' => (int) ($added[$unit->id] ?? 0),
                        'total' => (int) ($totals[$unit->id] ?? 0),
                        'url' => $unit->texts_exists ? route('courses.vocab.unit', $unit->number) : null,
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    public function runFor(User $user, VocabUnit $unit): VocabUnitRun
    {
        return VocabUnitRun::query()->firstOrCreate(['user_id' => $user->id, 'vocab_unit_id' => $unit->id]);
    }

    /**
     * All the new words of a lesson in the book's order, section after section.
     *
     * @return Collection<int, VocabItem>
     */
    public function wordsOf(VocabUnit $unit): Collection
    {
        return $unit->texts()->with('items')->get()->flatMap(fn ($text) => $text->items)->values();
    }

    /**
     * Where each of a lesson's words stands for this learner: already added
     * from this course ("added"), or already one of their lesson words
     * ("in_words", with how strong it is), or neither.
     *
     * @return array<int, array{state: string, strength: string|null}> by vocab item id
     */
    public function statuses(User $user, Collection $items): array
    {
        $mine = $this->words($user)->whereIn('vocab_item_id', $items->pluck('id'))->pluck('vocab_item_id')->flip();
        $lesson = $user->vocabularyWords()->lessonWords()->get(['word', 'repetitions', 'interval_days', 'last_reviewed_at'])
            ->keyBy(fn (VocabularyWord $word): string => mb_strtolower(trim($word->word)));

        return $items->mapWithKeys(function (VocabItem $item) use ($mine, $lesson): array {
            if ($mine->has($item->id)) {
                return [$item->id => ['state' => 'added', 'strength' => null]];
            }

            $twin = $lesson->get(mb_strtolower(trim($item->phrase)));

            return [$item->id => $twin === null
                ? ['state' => 'new', 'strength' => null]
                : ['state' => 'in_words', 'strength' => $twin->strengthLevel() >= self::STRONG_FROM_LEVEL ? 'Strong' : 'Learning']];
        })->all();
    }

    /**
     * Adds the chosen words of a lesson to the learner's Vocab in Use review.
     * A word already added is left alone; one that is already a lesson word
     * is skipped unless $anyway names it. Returns how many were added.
     *
     * @param  list<int>  $itemIds
     * @param  list<int>  $anyway  Item ids to add even though the learner has them as lesson words.
     */
    public function addWords(User $user, VocabUnit $unit, array $itemIds, array $anyway = []): int
    {
        $items = $this->wordsOf($unit)->whereIn('id', $itemIds);
        $statuses = $this->statuses($user, $items);
        $added = 0;

        foreach ($items as $item) {
            $state = $statuses[$item->id]['state'];

            if ($state === 'added' || ($state === 'in_words' && ! in_array($item->id, $anyway, true))) {
                continue;
            }

            $user->vocabularyWords()->create([
                'source' => VocabularyWord::SOURCE_VOCAB,
                'vocab_item_id' => $item->id,
                'word' => $item->phrase,
                'meaning' => $item->gloss,
                'meaning_fa' => $item->phrase_fa,
                'example' => $item->sentence,
                'next_review_at' => now(),
            ]);

            $added++;
        }

        if ($added > 0) {
            $this->ring->record($user, 'vocab');
        }

        return $added;
    }

    /**
     * The learner got to the end of the lesson's word list: the lesson is
     * done, and reading it counts for today's ring.
     */
    public function finish(User $user, VocabUnit $unit): VocabUnitRun
    {
        $run = $this->runFor($user, $unit);

        if ($run->finished_at === null) {
            $run->update(['finished_at' => now()]);
        }

        $this->ring->record($user, 'vocab');

        return $run;
    }

    /**
     * The course in numbers for the Progress page.
     *
     * @return array{added: int, strong: int}
     */
    public function stats(User $user): array
    {
        $words = $this->words($user)->get(['repetitions', 'interval_days', 'last_reviewed_at']);

        return [
            'added' => $words->count(),
            'strong' => $words->filter(fn (VocabularyWord $word): bool => $word->strengthLevel() >= self::STRONG_FROM_LEVEL)->count(),
        ];
    }

    /**
     * @return Builder<VocabularyWord>
     */
    private function words(User $user)
    {
        return VocabularyWord::query()->where('learner_id', $user->id)->fromVocabCourse();
    }
}
