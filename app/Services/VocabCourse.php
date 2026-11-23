<?php

namespace App\Services;

use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabProgress;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Vocab in Use course (units 5-94 of the Cambridge book): the map of
 * units, how far a learner got, and the review cards they saved. It sits
 * outside the 24 missions on purpose — nothing here touches the program
 * day, the streak, the daily ring, the reminders or the Review tab's
 * number. See App\Services\Courses for how it is listed.
 */
class VocabCourse
{
    public const KEY = 'vocab';

    public const NAME = 'Vocab in Use';

    public const BLURB = 'Words in sentences from the Cambridge book';

    /**
     * A sentence comes back for the first time a day after it was saved;
     * after that every card follows its own schedule (see VocabProgress).
     */
    private const FIRST_REVIEW_AFTER_DAYS = 1;

    /** A unit whose sentences are fresher than this (0-100) on average is shown as fresh. */
    private const FADING_BELOW = 60;

    public function totalUnits(): int
    {
        return VocabUnit::query()->count();
    }

    public function openedUnits(User $user): int
    {
        return VocabUnitRun::query()->where('user_id', $user->id)->count();
    }

    /**
     * Review cards (saved sentences) whose time has come. Counted here and
     * on the course's own card only, never in the Review tab.
     */
    public function dueCards(User $user): int
    {
        return VocabProgress::query()
            ->where('user_id', $user->id)
            ->where('next_review_at', '<=', now())
            ->count();
    }

    /**
     * The 90 units grouped by the book's 16 sections, each with what this
     * learner did there. A unit without its texts built yet is not ready
     * and cannot be opened.
     *
     * @return list<array{number: int, name: string, units: list<array{number: int, title: string, ready: bool, state: string, review: string|null, url: string|null}>}>
     */
    public function sections(User $user): array
    {
        $runs = VocabUnitRun::query()->where('user_id', $user->id)->get()->keyBy('vocab_unit_id');
        $reviewStates = $this->reviewStates($user);

        return VocabUnit::query()
            ->withExists('texts')
            ->orderBy('number')
            ->get()
            ->groupBy('book_section')
            ->map(fn ($units, $section): array => [
                'number' => (int) $section,
                'name' => $units->first()->book_section_name,
                'units' => $units->map(function (VocabUnit $unit) use ($runs, $reviewStates): array {
                    $run = $runs->get($unit->id);

                    return [
                        'number' => $unit->number,
                        'title' => $unit->title,
                        'ready' => (bool) $unit->texts_exists,
                        'state' => $run === null ? 'new' : ($run->finished_at ? 'done' : 'open'),
                        'review' => $reviewStates[$unit->id] ?? null,
                        'url' => $unit->texts_exists ? route('courses.vocab.unit', $unit->number) : null,
                    ];
                })->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * How fresh each unit's saved sentences are in memory: due (a card's
     * time has come), fading (the rest are going stale) or fresh. A unit
     * with no saved sentence has no entry.
     *
     * @return array<int, string> by unit id
     */
    public function reviewStates(User $user): array
    {
        return $this->cards($user)
            ->groupBy(fn (VocabProgress $card): int => $card->text->vocab_unit_id)
            ->map(function ($cards): string {
                if ($cards->contains(fn (VocabProgress $card): bool => $card->isDue())) {
                    return 'due';
                }

                return $cards->avg(fn (VocabProgress $card): int => $card->freshness()) < self::FADING_BELOW ? 'fading' : 'fresh';
            })
            ->all();
    }

    /**
     * The three numbers on the course home: saved sentences, the ones that
     * stuck (a 3-week gap or more) and the tricky ones.
     *
     * @return array{cards: int, strong: int, tricky: int}
     */
    public function stats(User $user): array
    {
        $cards = $this->cards($user);

        return [
            'cards' => $cards->count(),
            'strong' => $cards->filter(fn (VocabProgress $card): bool => $card->strengthLevel() >= 4)->count(),
            'tricky' => $cards->filter(fn (VocabProgress $card): bool => $card->isTricky())->count(),
        ];
    }

    /**
     * The saved words of the sentences missed four times in a row.
     *
     * @return list<string>
     */
    public function trickyPhrases(User $user): array
    {
        $ids = $this->cards($user)->filter(fn (VocabProgress $card): bool => $card->isTricky())->flatMap(fn (VocabProgress $card): array => $card->saved_item_ids);

        return VocabItem::query()->whereIn('id', $ids)->orderBy('phrase')->pluck('phrase')->unique()->values()->all();
    }

    /**
     * @return Collection<int, VocabProgress>
     */
    private function cards(User $user): Collection
    {
        return VocabProgress::query()->where('user_id', $user->id)->with('text:id,vocab_unit_id')->get();
    }

    public function runFor(User $user, VocabUnit $unit): VocabUnitRun
    {
        return VocabUnitRun::query()->firstOrCreate(['user_id' => $user->id, 'vocab_unit_id' => $unit->id]);
    }

    /**
     * Saving a word saves its whole sentence as one review card; words that
     * share a sentence share the card.
     */
    public function saveForReview(User $user, VocabItem $item): VocabProgress
    {
        $card = VocabProgress::query()->firstOrNew([
            'user_id' => $user->id,
            'vocab_text_id' => $item->vocab_text_id,
            'sentence_index' => $item->sentence_index,
        ]);

        if (! $card->exists) {
            $card->next_review_at = now()->addDays(self::FIRST_REVIEW_AFTER_DAYS);
        }

        $card->saved_item_ids = collect($card->saved_item_ids)->push($item->id)->unique()->values()->all();
        $card->save();

        return $card;
    }

    /**
     * Takes a word back out; the card goes when no word of its sentence is left.
     */
    public function removeFromReview(User $user, VocabItem $item): void
    {
        $card = VocabProgress::query()
            ->where('user_id', $user->id)
            ->where('vocab_text_id', $item->vocab_text_id)
            ->where('sentence_index', $item->sentence_index)
            ->first();

        if ($card === null) {
            return;
        }

        $left = collect($card->saved_item_ids)->reject(fn (int $id): bool => $id === $item->id)->values()->all();

        if ($left === []) {
            $card->delete();

            return;
        }

        $card->update(['saved_item_ids' => $left]);
    }

    /**
     * The ids of the words this learner saved in one unit.
     *
     * @return list<int>
     */
    public function savedItemIds(User $user, VocabUnit $unit): array
    {
        return VocabProgress::query()
            ->where('user_id', $user->id)
            ->whereIn('vocab_text_id', $unit->texts()->pluck('id'))
            ->pluck('saved_item_ids')
            ->flatten()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
}
