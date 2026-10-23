<?php

namespace App\Services;

use App\Models\MissionRun;
use App\Models\User;
use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Support\Facades\Cache;

/**
 * What every automatic phone reminder checks before it buzzes, so learners
 * get few, useful alerts instead of a stream of generic ones: whether
 * today's mission day is already done, how long they've been away, how many
 * reminders they've had today, and the one line saying exactly what's
 * waiting for them.
 */
class Reminders
{
    use WritesPersianNumbers;

    /**
     * Most automatic phone alerts one learner gets in a local day — the
     * reminders (study, streak, review, comeback, pair streak) and the
     * news about their own day (a friend finished, the finish line, a
     * streak badge) together. Messages and invites from a person don't count.
     */
    public const DAILY_LIMIT = 3;

    /**
     * Places the news alerts leave free for the evening's reminder, the
     * one that can still save the day.
     */
    public const KEPT_FOR_REMINDERS = 1;

    /**
     * After this many days without any activity the daily reminders stop;
     * only the comeback alerts (COMEBACK_DAYS) were left to bring them back.
     */
    public const AWAY_DAYS = 7;

    /**
     * Days away on which one comeback alert goes out.
     *
     * @var list<int>
     */
    public const COMEBACK_DAYS = [2, 5];

    public function __construct(private readonly ProgramPlanner $planner) {}

    /**
     * One short Persian line naming what's waiting — "روز ۳ از M05:
     * Listening، حدود ۸ دقیقه" — or null once the whole roadmap is done.
     * It starts with a Persian word so the phone lays it out right to left.
     */
    public function whatsWaiting(User $user): ?string
    {
        $today = $this->planner->plan($user)['today'];

        if ($today['kind'] === 'mission_day' && $today['mission']) {
            $left = collect($today['steps'])->where('done', false);
            $next = $left->first() ?? collect($today['steps'])->last();
            $minutes = max(1, $left->sum('minutes'));
            $day = $this->persianNumber($today['dayNumber']);

            return "روز {$day} از {$today['mission']->code}: {$next['label']}، حدود {$this->persianNumber($minutes)} دقیقه";
        }

        if (in_array($today['kind'], ['start_next', 'checkpoint'], true) && $today['nextMissionCode']) {
            return "ماموریت بعدی آماده‌ست: {$today['nextMissionCode']}";
        }

        return null;
    }

    /**
     * The quickest thing that adds a slice to today's streak ring: today's
     * review when words are due, else the next step that's waiting.
     */
    public function quickestNext(User $user): ?string
    {
        $due = $user->dailyReviewCount();

        if ($due > 0) {
            return "مرور {$this->persianNumber($due)} کلمه، حدود ۳ دقیقه";
        }

        return $this->whatsWaiting($user);
    }

    /**
     * The learner finished a mission day today (their own date) — nothing
     * left to remind them about. Read from each day's completion time, so
     * a mission's last day counts too.
     */
    public function finishedToday(User $user): bool
    {
        $today = $user->localToday()->toDateString();
        $timezone = $user->reminderTimezone();

        return $user->missionRuns()->with('mission')->get()->contains(
            fn (MissionRun $run) => collect($run->dayProgress())->contains(
                fn (array $day) => $day['completedAt']?->copy()->setTimezone($timezone)->toDateString() === $today,
            ),
        );
    }

    /**
     * Whole days since the learner last did anything, in their own
     * timezone; null if they never started.
     */
    public function daysAway(User $user): ?int
    {
        $lastActive = $user->activeDates()->first();

        return $lastActive ? (int) $lastActive->diffInDays($user->localToday()) : null;
    }

    public function isAway(User $user): bool
    {
        return ($this->daysAway($user) ?? 0) >= self::AWAY_DAYS;
    }

    /**
     * @param  int  $keepFree  places to leave unused (see KEPT_FOR_REMINDERS)
     */
    public function hasRoomToday(User $user, int $keepFree = 0): bool
    {
        return Cache::get($this->sentTodayKey($user), 0) < self::DAILY_LIMIT - $keepFree;
    }

    public function sentToday(User $user): int
    {
        return Cache::get($this->sentTodayKey($user), 0);
    }

    /**
     * Counts one automatic reminder towards today's DAILY_LIMIT.
     */
    public function countSent(User $user): void
    {
        $key = $this->sentTodayKey($user);

        Cache::add($key, 0, now()->addDays(2));
        Cache::increment($key);
    }

    private function sentTodayKey(User $user): string
    {
        return 'reminders-sent:'.$user->id.':'.$user->localToday()->toDateString();
    }
}
