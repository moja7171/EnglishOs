<?php

namespace App\Services;

use App\Models\User;

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

    /** The week runs Saturday to Friday. */
    public const WEEK_LETTERS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

    public function __construct(private FriendActivity $activity) {}

    /**
     * Everything the story shows about $friend, for $viewer.
     *
     * @return array{
     *     ring: int,
     *     where: ?string,
     *     today: array{count: int, headline: string, ringNote: string, items: list<array{kind: string, title: string, line: string}>, last: ?string},
     *     week: array{days: list<array{letter: string, day: int, state: string, missionDay: ?int, today: bool}>, practised: int, amount: int, viewerPractised: int},
     *     overall: array{percent: int, position: ?array{code: string, title: string, day: int, dayCount: int, step: ?string}, streak: int, longest: int, finished: int, listens: int, badges: int}
     * }
     */
    public function for(User $viewer, User $friend): array
    {
        $ring = min(StreakRing::SLICES_FOR_FULL, $friend->streakRing()['todaySlices']);
        $position = $this->position($friend);

        return [
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
     * @return array{count: int, headline: string, ringNote: string, items: list<array{kind: string, title: string, line: string}>, last: ?string}
     */
    private function today(User $viewer, User $friend, int $ring): array
    {
        $date = $viewer->localToday()->toDateString();
        $items = [];

        foreach ($this->activity->missionDaysOn($friend, $date) as $day) {
            $items[] = [
                'kind' => 'mission',
                'title' => "مأموریت {$day['code']} · روز {$day['day']}".($day['finished'] ? ' تمام شد ✓' : ''),
                'line' => collect($day['stepKeys'])->map(fn (string $key) => $this->stepName($key))->implode('، '),
            ];
        }

        foreach ($this->activity->dayItems($friend, $viewer, $date) as $item) {
            $mapped = match ($item['kind']) {
                'listening' => ['گوش دادن', 'یک لیسنینگ روزانه'],
                'pi' => ['صحبت با Pi', 'تمرین صحبت کردن'],
                'review' => ['مرور', 'واژه‌ها و جمله‌ها'],
                'pair' => ['تمرین دونفره', '12 دقیقه با یک دوست'],
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
                1 => '1 از 3 · یک کار دیگر استریک را نگه می‌دارد',
                2 => '2 از 3 · استریک امروز نگه داشته شد ✓',
                default => 'حلقهٔ امروز کامل شد ✓',
            },
            'items' => $items,
            'last' => $count === 0 ? $this->lastPracticeLine($viewer, $friend) : null,
        ];
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

        return [
            'days' => $days,
            'practised' => $row['days'],
            'amount' => $row['amount'],
            'viewerPractised' => $this->activity->weekRow($viewer, $start, $today)['days'],
        ];
    }

    /**
     * @param  array{code: string, title: string, day: int, dayCount: int, step: ?string}|null  $position
     * @return array{percent: int, position: ?array{code: string, title: string, day: int, dayCount: int, step: ?string}, streak: int, longest: int, finished: int, listens: int, badges: int}
     */
    private function overall(User $friend, ?array $position): array
    {
        $run = $friend->currentInProgressMissionRun();

        return [
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
