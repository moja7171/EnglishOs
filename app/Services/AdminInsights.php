<?php

namespace App\Services;

use App\Models\AiUsageLog;
use App\Models\DirectMessage;
use App\Models\ErrorLogItem;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\NotebookEntry;
use App\Models\PairPracticeSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Everything the admin page (/admin) shows: who is active, how much AI each
 * learner uses, who needs attention, one learner's full picture, and which
 * mission steps learners leave, take longest on or find hardest.
 *
 * Read-only, and never the content of anyone's messages — counts only.
 */
class AdminInsights
{
    /** A struggling run, or no new step for this many days while still coming back. */
    public const STUCK_DAYS = 3;

    /** Program days behind one-a-day before a learner is flagged. */
    public const BEHIND_DAYS = 3;

    /** A broken streak is news for this many days after the last active day. */
    public const STREAK_BROKEN_WITHIN_DAYS = 7;

    /** Only a streak at least this long is worth flagging when it breaks. */
    public const STREAK_WORTH_FLAGGING = 3;

    /** An in-progress run untouched this long counts as left at its step. */
    public const ABANDONED_AFTER_DAYS = 7;

    /** A longer gap between two steps is a break, not time spent on the step. */
    public const STEP_GAP_CAP_MINUTES = 120;

    /** How far back AI usage and step times are read. */
    public const WINDOW_DAYS = 30;

    /** Where each unsuccessful outcome is counted in a usage row. */
    private const OUTCOME_COLUMNS = [
        AiUsageLog::OUTCOME_FAILED => 'failed',
        AiUsageLog::OUTCOME_LIMITED => 'limited',
        AiUsageLog::OUTCOME_BLOCKED => 'blocked',
    ];

    /**
     * @return array{today: int, week: int, month: int, total: int}
     */
    public function activeUserCounts(): array
    {
        $seenSince = fn (Carbon $since) => User::query()->where('last_seen_at', '>=', $since)->count();

        return [
            'today' => $seenSince(now()->startOfDay()),
            'week' => $seenSince(now()->subDays(7)),
            'month' => $seenSince(now()->subDays(30)),
            'total' => User::query()->count(),
        ];
    }

    /**
     * One row per learner, most AI used in the window first. Counts are
     * successful requests unless named otherwise; "share" is the learner's
     * part of all of today's requests on the app's key, in percent.
     *
     * @return Collection<int, array{user: User, todayApp: int, todayOwn: int, monthApp: int, monthOwn: int, failed: int, limited: int, blocked: int, share: int}>
     */
    public function usersWithAiUsage(string $search = ''): Collection
    {
        $usage = $this->aiUsageTotals();
        $appToday = max(1, $usage->sum('todayApp'));

        return User::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->get()
            ->map(function (User $user) use ($usage, $appToday) {
                $row = $usage->get($user->id, $this->emptyUsage());

                return ['user' => $user, ...$row, 'share' => (int) round($row['todayApp'] / $appToday * 100)];
            })
            ->sortByDesc(fn (array $row) => [$row['monthApp'] + $row['monthOwn'], $row['user']->last_seen_at?->getTimestamp() ?? 0])
            ->values();
    }

    /**
     * @return array{todayApp: int, todayOwn: int, monthApp: int, monthOwn: int, failed: int, limited: int, blocked: int}
     */
    public function aiUsageFor(User $user): array
    {
        return $this->aiUsageTotals($user->id)->get($user->id, $this->emptyUsage());
    }

    /**
     * @return Collection<int, array{todayApp: int, todayOwn: int, monthApp: int, monthOwn: int, failed: int, limited: int, blocked: int}>
     */
    private function aiUsageTotals(?int $userId = null): Collection
    {
        return AiUsageLog::query()
            ->whereNotNull('user_id')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('user_id, key_source, outcome, COUNT(*) as month_count, SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as today_count', [now()->startOfDay()])
            ->groupBy('user_id', 'key_source', 'outcome')
            ->get()
            ->groupBy('user_id')
            ->map(function (Collection $rows) {
                $usage = $this->emptyUsage();

                foreach ($rows as $row) {
                    $month = (int) $row->month_count;
                    $today = (int) $row->today_count;

                    if ($row->outcome === AiUsageLog::OUTCOME_OK) {
                        $side = $row->key_source === AiUsageLog::KEY_OWN ? 'Own' : 'App';
                        $usage["today{$side}"] += $today;
                        $usage["month{$side}"] += $month;
                    } elseif (isset(self::OUTCOME_COLUMNS[$row->outcome])) {
                        $usage[self::OUTCOME_COLUMNS[$row->outcome]] += $month;
                    }
                }

                return $usage;
            });
    }

    /**
     * @return array{todayApp: int, todayOwn: int, monthApp: int, monthOwn: int, failed: int, limited: int, blocked: int}
     */
    private function emptyUsage(): array
    {
        return ['todayApp' => 0, 'todayOwn' => 0, 'monthApp' => 0, 'monthOwn' => 0, 'failed' => 0, 'limited' => 0, 'blocked' => 0];
    }

    /**
     * Learners who need a look: stuck on a step, behind their finish line,
     * or whose streak just broke. One row per learner with every reason.
     *
     * @return list<array{user: User, reasons: list<string>}>
     */
    public function attentionList(): array
    {
        $missions = Mission::query()->get()->keyBy('id');
        $rows = [];

        $learners = User::query()
            ->whereNotNull('program_started_at')
            ->orWhereHas('missionRuns')
            ->get();

        foreach ($learners as $user) {
            $reasons = array_values(array_filter([
                $this->stuckReason($user, $missions),
                $this->behindReason($user),
                $this->brokenStreakReason($user),
            ]));

            if ($reasons !== []) {
                $rows[] = ['user' => $user, 'reasons' => $reasons];
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Mission>  $missions
     */
    private function stuckReason(User $user, Collection $missions): ?string
    {
        $run = $user->currentInProgressMissionRun();

        if ($run === null) {
            return null;
        }

        $run->setRelation('mission', $missions->get($run->mission_id) ?? $run->mission);
        $stepKey = $run->currentStepKey();
        $where = $run->mission->code.($stepKey !== null ? ' › '.$run->mission->stepLabel($stepKey) : '');

        if ($run->isStruggling()) {
            return "Stuck: {$run->struggle_signal_count} hard misses on {$where}";
        }

        $lastStep = $run->evidence()->max('created_at');
        $lastStepAt = $lastStep !== null ? Carbon::parse($lastStep) : $run->started_at;

        if ($lastStepAt === null || $lastStepAt->gt(now()->subDays(self::STUCK_DAYS))) {
            return null;
        }

        // Still opening the app, but not moving past the step.
        if ($user->last_seen_at === null || $user->last_seen_at->lt($lastStepAt->copy()->addDay())) {
            return null;
        }

        $days = (int) $lastStepAt->diffInDays(now());

        return "Stuck: on {$where} for {$days} days";
    }

    private function behindReason(User $user): ?string
    {
        $behind = $this->daysBehind($user);

        return $behind !== null && $behind >= self::BEHIND_DAYS
            ? "Behind: {$behind} program days behind one a day"
            : null;
    }

    /**
     * Program days behind doing one a day since the program started — the
     * days that already ended minus the days done. Null before the start.
     */
    public function daysBehind(User $user, ?array $forecast = null): ?int
    {
        $forecast ??= (new FinishLine)->forecast($user);

        if ($forecast === null) {
            return null;
        }

        $timezone = $user->reminderTimezone();
        $daysEnded = (int) $user->program_started_at->copy()->setTimezone($timezone)->startOfDay()
            ->diffInDays(now($timezone)->startOfDay());

        return max(0, min(ProgramPlanner::TOTAL_DAYS, $daysEnded) - $forecast['daysDone']);
    }

    private function brokenStreakReason(User $user): ?string
    {
        $lastActive = $user->activeDates()->first();

        if ($lastActive === null) {
            return null;
        }

        $daysAway = (int) abs($lastActive->diffInDays($user->localToday()));

        if ($daysAway <= 2 || $daysAway > self::STREAK_BROKEN_WITHIN_DAYS) {
            return null;
        }

        $length = $user->lastStreakLength();

        return $length >= self::STREAK_WORTH_FLAGGING
            ? "Streak broke: {$length} days, last active {$daysAway} days ago"
            : null;
    }

    /**
     * One learner's whole picture for /admin/users/{user}.
     *
     * @return array<string, mixed>
     */
    public function userDetail(User $user): array
    {
        $run = $user->currentInProgressMissionRun();
        $stepKey = $run?->currentStepKey();
        $forecast = (new FinishLine)->forecast($user);

        return [
            'mission' => $run?->mission,
            'stepLabel' => $stepKey !== null ? $run->mission->stepLabel($stepKey) : null,
            'missionsDone' => $user->missionsCompletedCount(),
            'streak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
            'forecast' => $forecast,
            'daysBehind' => $this->daysBehind($user, $forecast),
            'practiceMinutes' => $user->totalPracticeMinutes(),
            'listens' => $user->audioListens()->selectRaw('source, COUNT(*) as total')->groupBy('source')->pluck('total', 'source')->map(fn ($total) => (int) $total)->all(),
            'listeningDays' => $user->listeningLogs()->count(),
            'recordings' => Evidence::query()
                ->join('mission_runs', 'mission_runs.id', '=', 'evidences.mission_run_id')
                ->where('mission_runs.learner_id', $user->id)
                ->where('evidences.type', Evidence::TYPE_AUDIO)
                ->count(),
            'piDays' => $user->piPracticeLogs()->count(),
            'pairSessions' => $this->pairSessionsQuery($user)->count(),
            'pairSessionsFinished' => $this->pairSessionsQuery($user)->whereNotNull('finished_at')->count(),
            'lastPairSession' => $this->pairSessionsQuery($user)->max('created_at'),
            'words' => $user->vocabularyWords()->count(),
            'dueReviews' => $user->dueReviewCount(),
            'topErrorCategories' => ErrorLogItem::query()
                ->join('mission_runs', 'mission_runs.id', '=', 'error_log_items.mission_run_id')
                ->where('mission_runs.learner_id', $user->id)
                ->whereNotNull('error_log_items.category')
                ->selectRaw('error_log_items.category as category, COUNT(*) as total')
                ->groupBy('error_log_items.category')
                ->orderByDesc('total')
                ->limit(3)
                ->pluck('total', 'category')
                ->all(),
            'topNotebookMistakes' => $user->notebookEntries()
                ->where('kind', NotebookEntry::KIND_MISTAKE)
                ->orderByDesc('times_seen')
                ->limit(3)
                ->get(['original', 'better', 'times_seen']),
            'pushDevices' => $user->pushSubscriptions()->count(),
            'lastPushDeviceAt' => $user->pushSubscriptions()->max('updated_at'),
            'storageBytes' => $this->storageBytes($user),
            'messagesSent' => DirectMessage::query()->where('sender_id', $user->id)->count(),
            'messagesReceived' => DirectMessage::query()->where('recipient_id', $user->id)->count(),
            'ai' => $this->aiUsageFor($user),
            'appKeyRequestsToday' => (new AiUsage)->appKeyRequestsToday($user),
        ];
    }

    /**
     * @return Builder<PairPracticeSession>
     */
    private function pairSessionsQuery(User $user): Builder
    {
        return PairPracticeSession::query()->where(fn ($query) => $query->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id));
    }

    /**
     * What this learner's files take on the server: their mission
     * recordings, their own upload folders and their photo. Best-effort —
     * a missing file just counts as nothing.
     */
    public function storageBytes(User $user): int
    {
        $public = Storage::disk('public');
        $local = Storage::disk('local');
        $bytes = 0;

        $size = function ($disk, string $path): int {
            try {
                return $disk->exists($path) ? (int) $disk->size($path) : 0;
            } catch (Throwable) {
                return 0;
            }
        };

        $recordings = Evidence::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'evidences.mission_run_id')
            ->where('mission_runs.learner_id', $user->id)
            ->where('evidences.type', Evidence::TYPE_AUDIO)
            ->pluck('evidences.content_ref');

        foreach ($recordings as $ref) {
            $url = json_decode((string) $ref, true)['url'] ?? $ref;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $path = str_contains($url, '/storage/') ? substr($url, strpos($url, '/storage/') + strlen('/storage/')) : ltrim($url, '/');
            $bytes += $size($public, $path);
        }

        foreach ([[$public, "speaking-recall/{$user->id}"], [$local, "partner-sessions/{$user->id}"], [$local, "instructor-messages/{$user->id}"], [$local, "direct-messages/{$user->id}"]] as [$disk, $directory]) {
            try {
                foreach ($disk->allFiles($directory) as $file) {
                    $bytes += $size($disk, $file);
                }
            } catch (Throwable) {
                // An unreadable folder counts as empty.
            }
        }

        if ($user->avatar_path) {
            $bytes += $size($public, $user->avatar_path);
        }

        return $bytes;
    }

    /**
     * Where unfinished runs were left: the step an in-progress run is on
     * when nothing happened in it for ABANDONED_AFTER_DAYS. Most first.
     *
     * @return list<array{mission: string, step: string, learners: int}>
     */
    public function abandonedSteps(): array
    {
        $cutoff = now()->subDays(self::ABANDONED_AFTER_DAYS);
        $counts = [];

        $runs = MissionRun::query()
            ->with('mission')
            ->where('status', MissionRun::STATUS_IN_PROGRESS)
            ->withMax('evidence', 'created_at')
            ->get();

        foreach ($runs as $run) {
            $lastActivity = $run->evidence_max_created_at !== null ? Carbon::parse($run->evidence_max_created_at) : $run->started_at;

            if ($lastActivity === null || $lastActivity->gt($cutoff)) {
                continue;
            }

            $stepKey = $run->currentStepKey();

            if ($stepKey === null) {
                continue;
            }

            $key = $run->mission->code.'|'.$stepKey;
            $counts[$key] ??= ['mission' => $run->mission->code, 'step' => $run->mission->stepLabel($stepKey), 'learners' => 0];
            $counts[$key]['learners']++;
        }

        return collect($counts)->sortByDesc('learners')->values()->all();
    }

    /**
     * Average real minutes per step: the time from the previous step's save
     * (or the run's start) to this step's save, leaving out gaps longer
     * than STEP_GAP_CAP_MINUTES, which are breaks. Longest first.
     *
     * @return list<array{mission: string, step: string, minutes: int, samples: int, plannedMinutes: int}>
     */
    public function stepTimes(): array
    {
        $missions = Mission::query()->get()->keyBy('id');
        $runs = MissionRun::query()->where('started_at', '>=', now()->subDays(self::WINDOW_DAYS))->get()->keyBy('id');
        $evidence = Evidence::query()
            ->whereIn('mission_run_id', $runs->keys())
            ->orderBy('mission_run_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['mission_run_id', 'phase', 'created_at']);

        $samples = [];

        foreach ($evidence->groupBy('mission_run_id') as $runId => $rows) {
            $run = $runs->get($runId);
            $mission = $missions->get($run->mission_id);

            if ($mission === null) {
                continue;
            }

            $stepKeys = $mission->stepKeys();
            $previous = $run->started_at;
            $seen = [];

            foreach ($rows as $row) {
                // Only a step's first save counts; extra rows (feedback, redo) are not a new step.
                if (! in_array($row->phase, $stepKeys, true) || isset($seen[$row->phase])) {
                    continue;
                }

                $seen[$row->phase] = true;

                if ($previous !== null) {
                    $minutes = $previous->diffInMinutes($row->created_at);

                    if ($minutes >= 0 && $minutes <= self::STEP_GAP_CAP_MINUTES) {
                        $samples[$mission->code.'|'.$row->phase][] = $minutes;
                    }
                }

                $previous = $row->created_at;
            }
        }

        $byCode = $missions->keyBy('code');

        return collect($samples)
            ->map(function (array $minutes, string $key) use ($byCode) {
                [$code, $stepKey] = explode('|', $key, 2);
                $mission = $byCode->get($code);

                return [
                    'mission' => $code,
                    'step' => $mission->stepLabel($stepKey),
                    'minutes' => (int) round(array_sum($minutes) / count($minutes)),
                    'samples' => count($minutes),
                    'plannedMinutes' => $mission->stepDuration($stepKey),
                ];
            })
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }

    /**
     * Steps by AI requests per learner in the window — every re-check and
     * retry is one more request, so a hard step rises to the top. Failed
     * and refused requests count too: the learner still tried.
     *
     * @return list<array{mission: string, step: string, requestsPerLearner: float, learners: int}>
     */
    public function hardestSteps(int $limit = 10): array
    {
        $missions = Mission::query()->get()->keyBy('code');

        return AiUsageLog::query()
            ->whereNotNull('step_key')
            ->whereNotNull('mission_code')
            ->whereNotNull('user_id')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->selectRaw('mission_code, step_key, COUNT(*) as requests, COUNT(DISTINCT user_id) as learners')
            ->groupBy('mission_code', 'step_key')
            ->get()
            ->map(fn ($row) => [
                'mission' => $row->mission_code,
                'step' => $missions->get($row->mission_code)?->stepLabel($row->step_key) ?? $row->step_key,
                'requestsPerLearner' => round($row->requests / max(1, $row->learners), 1),
                'learners' => (int) $row->learners,
            ])
            ->sortByDesc('requestsPerLearner')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * The mistakes most learners share: Error Log categories by how many
     * learners logged them, and notebook mistakes by how many learners made
     * the same one.
     *
     * @return array{categories: list<array{category: string, learners: int, items: int}>, notebook: list<array{label: ?string, better: string, learners: int}>}
     */
    public function commonMistakes(int $limit = 10): array
    {
        $categories = ErrorLogItem::query()
            ->join('mission_runs', 'mission_runs.id', '=', 'error_log_items.mission_run_id')
            ->whereNotNull('error_log_items.category')
            ->selectRaw('error_log_items.category as category, COUNT(DISTINCT mission_runs.learner_id) as learners, COUNT(*) as items')
            ->groupBy('error_log_items.category')
            ->orderByDesc('learners')
            ->orderByDesc('items')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'learners' => (int) $row->learners, 'items' => (int) $row->items])
            ->all();

        $notebook = NotebookEntry::query()
            ->where('kind', NotebookEntry::KIND_MISTAKE)
            ->selectRaw('dedupe_key, MAX(label) as label, MAX(better) as better, COUNT(DISTINCT learner_id) as learners')
            ->groupBy('dedupe_key')
            ->orderByDesc('learners')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'better' => (string) $row->better, 'learners' => (int) $row->learners])
            ->all();

        return ['categories' => $categories, 'notebook' => $notebook];
    }
}
