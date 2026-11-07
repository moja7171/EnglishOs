<?php

namespace App\Services;

use App\Models\AudioListen;
use App\Models\DayActivity;
use App\Models\NotebookEntry;
use App\Models\PairGame;
use App\Models\PairPracticeSession;
use App\Models\PiPracticeLog;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Support\Carbon;

/**
 * The three-page story a friend's photo opens on the Friends page: what
 * they did today, how their week went, and where they are overall. Every
 * line is Persian and says exactly where the friend is. Counts, names and
 * dates only, never an answer — the same rule as the Board and a friend's
 * page (see FriendActivity).
 */
class FriendStory
{
    /** Persian names of the mission steps, by step key (a trailing _N is ignored). */
    public const STEP_NAMES = [
        'mission_brief' => 'آماده‌سازی',
        'vocabulary_builder' => 'واژه‌های جدید',
        'listening' => 'گوش دادن',
        'daily_listen' => 'دوباره گوش دادن',
        'grammar_in_context' => 'گرامر',
        'story_sequence' => 'داستان تصویری',
        'video_shadowing' => 'ویدیو و تکرار',
        'ai_conversation_1' => 'صحبت کردن',
        'picture_description' => 'توصیف تصویر',
        'reading_comprehension' => 'خواندن',
        'writing' => 'نوشتن',
        'error_log' => 'اشتباهات من',
        'ai_conversation_2' => 'صحبت پایانی',
        'mission_result' => 'نتیجهٔ مأموریت',
    ];

    /** Persian names of the kinds of things a day can hold (see FriendActivity::kindsByDate()). */
    public const KIND_NAMES = [
        'mission' => 'مأموریت',
        'listening' => 'گوش دادن',
        'pi' => 'صحبت با Pi',
        'review' => 'مرور',
        'pair' => 'تمرین دونفره',
        'game' => 'بازی',
        'ai-practice' => 'تمرین با Teddy',
    ];

    /** The week runs Saturday to Friday. */
    public const WEEK_LETTERS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    /** Persian names of a listening pick's three levels (ListeningPicks::LEVELS). */
    public const LEVEL_NAMES = ['سبک‌تر', 'متوسط', 'چالشی'];

    public function __construct(private FriendActivity $activity, private ListeningPicks $picks) {}

    /**
     * Everything the story shows about $friend, for $viewer.
     *
     * @return array{
     *     self: bool,
     *     ring: int,
     *     where: ?string,
     *     today: array{count: int, headline: string, ringNote: string, items: list<array{kind: string, title: string, line: string, details?: list<array{name: string, note: string, times: int}>}>, last: ?string},
     *     week: array{days: list<array{letter: string, day: int, state: string, missionDay: ?int, today: bool}>, practised: int, amount: int, viewerPractised: int},
     *     overall: array{percent: int, position: ?array{code: string, title: string, day: int, dayCount: int, step: ?string}, streak: int, longest: int, finished: int, listens: int, badges: int}
     * }
     */
    public function for(User $viewer, User $friend): array
    {
        $ring = min(StreakRing::SLICES_FOR_FULL, $friend->streakRing()['todaySlices']);
        $position = $this->position($friend);

        return [
            'self' => $viewer->is($friend),
            'ring' => $ring,
            'where' => $position ? $this->whereLine($position) : null,
            'today' => $this->today($viewer, $friend, $ring),
            'week' => $this->week($viewer, $friend),
            'overall' => $this->overall($friend, $position),
        ];
    }

    /**
     * The Persian name of a mission step.
     */
    public function stepName(string $key): string
    {
        return self::STEP_NAMES[$key]
            ?? self::STEP_NAMES[preg_replace('/_\d+$/', '', $key)]
            ?? str($key)->replace('_', ' ')->toString();
    }

    /**
     * Where the friend is in their open mission: which day of how many and
     * which step they are on right now. Null when no mission is open.
     *
     * @return array{code: string, title: string, day: int, dayCount: int, step: ?string}|null
     */
    private function position(User $friend): ?array
    {
        $run = $friend->currentInProgressMissionRun();

        if (! $run) {
            return null;
        }

        $days = $run->dayProgress();
        $current = collect($days)->search(fn (array $day) => ! $day['done']);
        $stepKey = $run->currentStepKey();

        return [
            'code' => $run->mission->code,
            'title' => $run->mission->title,
            'day' => $current === false ? count($days) : $current + 1,
            'dayCount' => count($days),
            'step' => $stepKey ? $this->stepName($stepKey) : null,
        ];
    }

    /**
     * @param  array{code: string, title: string, day: int, dayCount: int, step: ?string}  $position
     */
    private function whereLine(array $position): string
    {
        $line = "{$position['code']} · روز {$position['day']} از {$position['dayCount']}";

        return $position['step'] ? "{$line} · الان در «{$position['step']}»" : $line;
    }

    /**
     * @return array{count: int, headline: string, ringNote: string, items: list<array{kind: string, title: string, line: string, details?: list<array{name: string, note: string, times: int}>}>, last: ?string}
     */
    private function today(User $viewer, User $friend, int $ring): array
    {
        $date = $viewer->localToday()->toDateString();
        $items = [];

        foreach ($this->activity->missionDaysOn($friend, $date) as $day) {
            $items[] = [
                'kind' => 'mission',
                'title' => "مأموریت {$day['code']} · روز {$day['day']}".($day['finished'] ? ' تمام شد ✓' : ''),
                'line' => '',
                'details' => collect($day['stepKeys'])->map(fn (string $key) => ['name' => $this->stepName($key), 'note' => '', 'times' => 0])->all(),
            ];
        }

        $heard = $this->heardToday($friend);

        if ($heard !== []) {
            $times = array_sum(array_column($heard, 'times'));
            $items[] = ['kind' => 'listening', 'title' => "گوش دادن · {$times} بار", 'line' => '', 'details' => $heard];
        }

        foreach ($this->activity->dayItems($friend, $viewer, $date) as $item) {
            $mapped = match ($item['kind']) {
                'listening' => $heard === [] ? ['گوش دادن', 'یک لیسنینگ روزانه'] : null,
                'pi' => ['صحبت با Pi', $this->piLine($friend, $date)],
                'review' => ['مرور', $this->reviewLine($friend)],
                'pair' => ['تمرین دونفره', $this->pairLine($friend)],
                'ai-practice' => ['تمرین با Teddy', 'هم‌کلاسی هوشمند'],
                'game' => $item['title'] === 'A game'
                    ? ['بازی', 'در صفحهٔ بازی‌ها']
                    : ['بازی با تو', str_replace(' · team ', ' · امتیاز تیمی ', $item['line'])],
                default => null,
            };

            if ($mapped) {
                $items[] = ['kind' => $item['kind'], 'title' => $mapped[0], 'line' => $mapped[1]];
            }
        }

        $count = count($items);

        return [
            'count' => $count,
            'headline' => $count === 0 ? 'هنوز کاری نکرده' : "{$count} کار انجام داده",
            'ringNote' => match ($ring) {
                0 => 'حلقهٔ امروز خالی است',
                1 => '1 از 3 · یک کار دیگر زنجیرهٔ روزها را نگه می‌دارد',
                2 => '2 از 3 · زنجیرهٔ روزها امروز حفظ شد ✓',
                default => 'حلقهٔ امروز کامل شد ✓',
            },
            'items' => $items,
            'lastAt' => $this->lastActivityAt($friend, $date),
            'last' => $count === 0 ? $this->lastPracticeLine($viewer, $friend) : null,
        ];
    }

    /**
     * Which recordings $friend listened through today (a full 90% listen
     * each, see AudioListen): the daily picks by title and level, and the
     * lesson's own listening by mission and day, most-heard first.
     *
     * @return list<array{name: string, note: string, times: int}>
     */
    private function heardToday(User $friend): array
    {
        $start = $this->dayStart($friend);

        return $friend->audioListens()
            ->whereIn('source', [AudioListen::SOURCE_LISTENING_PICK, AudioListen::SOURCE_LISTENING])
            ->where('created_at', '>=', $start)
            ->get(['source', 'mission_code', 'day_number', 'pick_key'])
            ->groupBy(fn (AudioListen $listen) => $listen->source.'|'.$listen->mission_code.'|'.$listen->day_number.'|'.$listen->pick_key)
            ->map(function ($group) {
                $listen = $group->first();
                $level = $listen->pick_key ? (int) explode('-', $listen->pick_key)[1] : null;

                if ($listen->source === AudioListen::SOURCE_LISTENING_PICK) {
                    $title = $this->picks->forDay((string) $listen->mission_code, (int) $listen->day_number)[$level]['title'] ?? null;

                    return $title === null ? null : [
                        'name' => $title,
                        'note' => "{$listen->mission_code} · روز {$listen->day_number} · ".(self::LEVEL_NAMES[$level] ?? ''),
                        'times' => $group->count(),
                    ];
                }

                return [
                    'name' => 'لیسنینگ درس',
                    'note' => "{$listen->mission_code}".($listen->day_number ? " · روز {$listen->day_number}" : ''),
                    'times' => $group->count(),
                ];
            })
            ->filter()
            ->sortByDesc('times')
            ->values()
            ->all();
    }

    private function dayStart(User $friend): Carbon
    {
        return now($friend->reminderTimezone())->startOfDay()->setTimezone(config('app.timezone'));
    }

    private function reviewLine(User $friend): string
    {
        $start = $this->dayStart($friend);
        $count = VocabularyWord::where('learner_id', $friend->id)->where('last_reviewed_at', '>=', $start)->count()
            + NotebookEntry::where('learner_id', $friend->id)->where('last_reviewed_at', '>=', $start)->count();

        return $count > 0 ? "{$count} واژه و جمله مرور شد" : 'واژه‌ها و جمله‌ها';
    }

    private function piLine(User $friend, string $date): string
    {
        $log = PiPracticeLog::where('learner_id', $friend->id)->where('practiced_on', $date)->first();

        return $log ? "{$log->mission_code} · روز {$log->day_number} · تمرین صحبت کردن" : 'تمرین صحبت کردن';
    }

    private function pairLine(User $friend): string
    {
        $session = PairPracticeSession::query()
            ->where(fn ($query) => $query->where('user_a_id', $friend->id)->orWhere('user_b_id', $friend->id))
            ->where('finished_at', '>=', $this->dayStart($friend))
            ->with(['userA', 'userB'])
            ->latest('finished_at')
            ->first();

        return $session ? '12 دقیقه با '.$session->partnerFor($friend)->name : '12 دقیقه با یک دوست';
    }

    private function lastActivityAt(User $friend, string $date): ?string
    {
        $latest = DayActivity::where('learner_id', $friend->id)->where('on_date', $date)->max('created_at');

        return $latest === null ? null : Carbon::parse($latest, config('app.timezone'))->setTimezone($friend->reminderTimezone())->format('H:i');
    }

    private function lastPracticeLine(User $viewer, User $friend): string
    {
        $last = $this->activity->lastPracticedOn($friend);

        if ($last === null) {
            return 'هنوز تمرینی ثبت نکرده';
        }

        $days = (int) $last->diffInDays($viewer->localToday());

        return match (true) {
            $days <= 0 => 'آخرین تمرین: امروز',
            $days === 1 => 'آخرین تمرین: دیروز',
            default => "آخرین تمرین: {$days} روز پیش",
        };
    }

    /**
     * @return array{days: list<array{letter: string, day: int, state: string, missionDay: ?int, today: bool}>, practised: int, amount: int, viewerPractised: int}
     */
    private function week(User $viewer, User $friend): array
    {
        $today = $viewer->localToday();
        $start = $this->activity->weekStart($viewer);
        $row = $this->activity->weekRow($friend, $start, $today);

        $days = collect($row['week'])->map(fn (array $cell, int $index) => [
            'letter' => self::WEEK_LETTERS[$index],
            'day' => $cell['day'],
            'state' => match (true) {
                $cell['future'] => 'future',
                $cell['missionDay'] => 'mission',
                $cell['practised'] => 'practised',
                default => 'none',
            },
            'missionDay' => $cell['missionDay'] ? (int) ltrim($cell['tag'], 'D') : null,
            'today' => $cell['today'],
        ])->all();

        $kinds = $this->activity->kindsByDate($friend, $start, 7);
        $days = collect($days)->map(fn (array $day, int $index) => $day + [
            'kinds' => collect($kinds[$start->copy()->addDays($index)->toDateString()] ?? [])->map(fn (string $kind) => self::KIND_NAMES[$kind] ?? $kind)->all(),
        ])->all();
        $totals = collect($kinds)->flatten()->countBy()->map(fn (int $count, string $kind) => ['name' => self::KIND_NAMES[$kind] ?? $kind, 'days' => $count])->values()->all();

        return [
            'days' => $days,
            'totals' => $totals,
            'practised' => $row['days'],
            'amount' => $row['amount'],
            'viewerPractised' => $this->activity->weekRow($viewer, $start, $today)['days'],
        ];
    }

    /**
     * @param  array{code: string, title: string, day: int, dayCount: int, step: ?string}|null  $position
     * @return array{percent: int, position: ?array{code: string, title: string, day: int, dayCount: int, step: ?string}, streak: int, longest: int, finished: int, listens: int, badges: int, missionPercent: ?int, missionDays: list<string>, practiceDays: int, words: int, games: int, joinedDaysAgo: int}
     */
    private function overall(User $friend, ?array $position): array
    {
        $run = $friend->currentInProgressMissionRun();

        $dayStates = collect($run?->dayProgress() ?? [])->map(fn (array $day) => $day['done'] ? 'done' : 'todo')->all();
        $current = array_search('todo', $dayStates, true);

        if ($current !== false) {
            $dayStates[$current] = 'current';
        }

        return [
            'missionPercent' => $run?->progressPercent(),
            'missionDays' => $dayStates,
            'practiceDays' => DayActivity::where('learner_id', $friend->id)->distinct()->count('on_date'),
            'words' => VocabularyWord::where('learner_id', $friend->id)->count(),
            'games' => PairGame::involving($friend)->where('stage', PairGame::STAGE_DONE)->count(),
            'joinedDaysAgo' => (int) $friend->created_at->diffInDays(now()),
            'percent' => (int) round(($friend->missionsFinishedCount() * 100 + ($run?->progressPercent() ?? 0)) / 24),
            'position' => $position,
            'streak' => $friend->currentStreak(),
            'longest' => $friend->longestStreak(),
            'finished' => $friend->missionsFinishedCount(),
            'listens' => $friend->totalListensCount(),
            'badges' => collect(app(GameBadges::class)->for($friend))->where('earned', true)->count(),
        ];
    }
}
