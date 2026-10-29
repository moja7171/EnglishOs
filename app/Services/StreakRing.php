<?php

namespace App\Services;

use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Throwable;

/**
 * The daily streak ring: every activity the learner finishes in a day is
 * one slice — a mission step, today's review (all of it, or 10 cards), the
 * listening tick, the Pi tick, a Pair Practice round — each kind at most
 * once a day, every mission step on its own. SLICES_TO_KEEP slices keep
 * the streak, SLICES_FOR_FULL fill the ring (and celebrate).
 *
 * A missed day no longer slips by silently: it uses a freeze 🧊, which the
 * learner can see. Everyone starts with STARTING_FREEZES and earns one for
 * every FULL_DAYS_PER_FREEZE full-ring days, holding MAX_FREEZES at most;
 * a missed day with none left ends the streak.
 *
 * The rules apply from the learner's streak_ring_since (sign-up day for
 * new learners); days before it keep the old rule — any one activity
 * counts and a single skipped day is forgiven — so no streak broke
 * overnight when the ring arrived. Everything is worked out fresh from the
 * stored slices (see DayActivity), never kept as a counter that could drift.
 */
class StreakRing
{
    public const SLICES_TO_KEEP = 2;

    public const SLICES_FOR_FULL = 3;

    public const REVIEW_CARDS_FOR_A_SLICE = 10;

    public const STARTING_FREEZES = 1;

    public const FULL_DAYS_PER_FREEZE = 7;

    public const MAX_FREEZES = 2;

    /**
     * state() per learner id for this request (the service is a singleton,
     * see AppServiceProvider); a new slice clears that learner's (forget()).
     *
     * @var array<int, array{current: int, longest: int, last: int, keptToday: bool, todaySlices: int, freezes: int, frozenDates: list<string>, keptDates: list<string>, fullDaysToNextFreeze: int}>
     */
    private array $states = [];

    /**
     * Adds one slice to the learner's ring for today, once per $activity.
     * True when it was new. The open screen hears about it right away
     * ("streak-ring-changed"), so the ring in the header fills as it happens.
     */
    public function record(User $learner, string $activity): bool
    {
        $slice = DayActivity::firstOrCreate([
            'learner_id' => $learner->id,
            'on_date' => $learner->localToday()->toDateString(),
            'activity' => $activity,
        ]);

        if (! $slice->wasRecentlyCreated) {
            return false;
        }

        try {
            Livewire::current()?->dispatch('streak-ring-changed');
        } catch (Throwable) {
            // Not inside a Livewire request — the ring catches up on its next poll.
        }

        return true;
    }

    /**
     * A mission step's first answer today (each step its own slice).
     */
    public function recordStep(Evidence $evidence): void
    {
        $learner = $evidence->missionRun?->learner;

        if ($learner) {
            $this->record($learner, "step:{$evidence->mission_run_id}:{$evidence->phase}");
        }
    }

    /**
     * After any review: today's review counts once either track (words, or
     * speaking and grammar) is all done, or after REVIEW_CARDS_FOR_A_SLICE
     * cards of it.
     */
    public function recordReview(User $learner): void
    {
        foreach (array_keys(User::REVIEW_TRACKS) as $track) {
            $reviewed = $learner->reviewedTodayCount($track);

            if ($reviewed > 0 && ($reviewed >= self::REVIEW_CARDS_FOR_A_SLICE || $learner->dailyReviewCount($track) === 0)) {
                $this->record($learner, 'review');

                return;
            }
        }
    }

    /**
     * Drops the learner's worked-out state — called whenever a slice is saved.
     */
    public function forget(int $learnerId): void
    {
        unset($this->states[$learnerId]);
    }

    public function slicesToday(User $learner): int
    {
        return DayActivity::where('learner_id', $learner->id)
            ->whereDate('on_date', $learner->localToday())
            ->count();
    }

    /**
     * Everything the streak shows, worked out once per learner per request.
     *
     * @return array{current: int, longest: int, last: int, keptToday: bool, todaySlices: int, freezes: int, frozenDates: list<string>, keptDates: list<string>, fullDaysToNextFreeze: int}
     */
    public function state(User $learner): array
    {
        return $this->states[$learner->id] ??= $this->work($learner);
    }

    /**
     * The first local day the ring's rules apply to this learner.
     */
    public function since(User $learner): Carbon
    {
        if ($learner->streak_ring_since) {
            return Carbon::parse($learner->streak_ring_since->toDateString());
        }

        return Carbon::parse(($learner->created_at ?? now())->copy()->setTimezone($learner->reminderTimezone())->toDateString());
    }

    /**
     * @return array{current: int, longest: int, last: int, keptToday: bool, todaySlices: int, freezes: int, frozenDates: list<string>, keptDates: list<string>, fullDaysToNextFreeze: int}
     */
    private function work(User $learner): array
    {
        $today = $learner->localToday();
        $since = $this->since($learner);

        $slices = DayActivity::where('learner_id', $learner->id)
            ->whereDate('on_date', '>=', $since)
            ->get(['on_date'])
            ->countBy(fn (DayActivity $slice) => $slice->on_date->toDateString());

        $oldDates = $learner->activeDates()->filter(fn (Carbon $date) => $date->lt($since))->values();
        $oldChains = $this->oldChains($oldDates);

        // Before the ring's first day (the day it arrived) the old rule is all there is.
        if ($since->gt($today)) {
            $alive = $oldDates->isNotEmpty() && abs($oldDates->first()->diffInDays($today)) <= 2;

            return [
                'current' => $alive ? $oldChains[0] : 0,
                'longest' => $oldChains === [] ? 0 : max($oldChains),
                'last' => $oldChains[0] ?? 0,
                'keptToday' => $oldDates->first()?->equalTo($today) ?? false,
                'todaySlices' => $slices->get($today->toDateString(), 0),
                'freezes' => self::STARTING_FREEZES,
                'frozenDates' => [],
                'keptDates' => $oldDates->map(fn (Carbon $date) => $date->toDateString())->all(),
                'fullDaysToNextFreeze' => self::FULL_DAYS_PER_FREEZE,
            ];
        }

        // A streak still alive under the old rule carries over.
        $streak = $oldDates->isNotEmpty() && abs($oldDates->first()->diffInDays($since)) <= 2 ? $oldChains[0] : 0;
        $longest = $oldChains === [] ? 0 : max($oldChains);
        $last = $oldChains[0] ?? 0;
        $freezes = self::STARTING_FREEZES;
        $fullDays = 0;
        $frozenDates = [];
        $keptDates = $oldDates->map(fn (Carbon $date) => $date->toDateString())->all();

        for ($day = $since->copy(); $day->lte($today); $day->addDay()) {
            $date = $day->toDateString();
            $count = $slices->get($date, 0);

            if ($count >= self::SLICES_FOR_FULL && ++$fullDays % self::FULL_DAYS_PER_FREEZE === 0) {
                $freezes = min(self::MAX_FREEZES, $freezes + 1);
            }

            if ($count >= self::SLICES_TO_KEEP) {
                $streak++;
                $last = $streak;
                $longest = max($longest, $streak);
                $keptDates[] = $date;
            } elseif ($date === $today->toDateString()) {
                // Today is still open.
            } elseif ($streak > 0 && $freezes > 0) {
                $freezes--;
                $frozenDates[] = $date;
            } else {
                $streak = 0;
            }
        }

        $todaySlices = $slices->get($today->toDateString(), 0);

        return [
            'current' => $streak,
            'longest' => $longest,
            'last' => $last,
            'keptToday' => $todaySlices >= self::SLICES_TO_KEEP,
            'todaySlices' => $todaySlices,
            'freezes' => $freezes,
            'frozenDates' => $frozenDates,
            'keptDates' => array_values(array_unique($keptDates)),
            'fullDaysToNextFreeze' => self::FULL_DAYS_PER_FREEZE - $fullDays % self::FULL_DAYS_PER_FREEZE,
        ];
    }

    /**
     * The old rule's runs of active days (a gap of one skipped day is
     * forgiven, two end the run), most recent first.
     *
     * @param  Collection<int, Carbon>  $dates  descending
     * @return list<int>
     */
    private function oldChains(Collection $dates): array
    {
        if ($dates->isEmpty()) {
            return [];
        }

        $chains = [];
        $length = 1;
        $previous = $dates->first();

        foreach ($dates->skip(1) as $date) {
            if (abs($previous->diffInDays($date)) <= 2) {
                $length++;
            } else {
                $chains[] = $length;
                $length = 1;
            }

            $previous = $date;
        }

        $chains[] = $length;

        return $chains;
    }
}
