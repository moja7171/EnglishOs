<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * In-person Pair Practice: which mission days two friends have BOTH
 * finished, the cards drawn from those days, each friend's progress today
 * and the pair's shared streak. A day counts once every one of its steps
 * has Evidence, so a day only one of them finished stays locked.
 */
class PairPractice
{
    public const VOCABULARY_CARDS = 12;

    public const GRAMMAR_CARDS = 8;

    public const SPEAKING_CARDS = 6;

    /**
     * Share of each stage's cards drawn from the newest shared mission; the
     * rest review older shared missions.
     */
    private const LATEST_MISSION_SHARE = 0.75;

    public function __construct(private readonly ProgramPlanner $planner) {}

    /**
     * Every mission day $user has finished, oldest first.
     *
     * @return Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}> keyed "M03-2"
     */
    public function learnedDays(User $user): Collection
    {
        return $user->missionRuns()->with('mission')->get()
            ->flatMap(fn (MissionRun $run) => collect($run->dayProgress())
                ->filter(fn (array $day) => $day['done'])
                ->map(fn (array $day, int $index) => [
                    'mission' => $run->mission,
                    'day' => $index + 1,
                    'completedAt' => $day['completedAt'],
                ])
                ->values())
            ->sortBy(fn (array $day) => $day['mission']->number() * 10 + $day['day'])
            ->keyBy(fn (array $day) => $day['mission']->code.'-'.$day['day']);
    }

    /**
     * @return Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>
     */
    public function sharedDays(User $a, User $b): Collection
    {
        return $this->learnedDays($a)->intersectByKeys($this->learnedDays($b));
    }

    /**
     * The next day that opens once the one who is behind finishes it — the
     * first day one of the two has done and the other hasn't.
     *
     * @return array{mission: Mission, day: int, waitingFor: User}|null
     */
    public function lockedDay(User $me, User $friend): ?array
    {
        $mine = $this->learnedDays($me);
        $theirs = $this->learnedDays($friend);

        if ($day = $mine->diffKeys($theirs)->first()) {
            return ['mission' => $day['mission'], 'day' => $day['day'], 'waitingFor' => $friend];
        }

        if ($day = $theirs->diffKeys($mine)->first()) {
            return ['mission' => $day['mission'], 'day' => $day['day'], 'waitingFor' => $me];
        }

        return null;
    }

    /**
     * Steps done out of the steps in today's mission day.
     *
     * @return array{done: int, total: int, finished: bool}
     */
    public function todayProgress(User $user): array
    {
        $today = $this->planner->plan($user)['today'];
        $steps = collect($today['steps']);
        $done = $steps->where('done', true)->count();

        return [
            'done' => $done,
            'total' => $steps->count(),
            'finished' => $today['dayCompletedToday'] || ($steps->isNotEmpty() && $done === $steps->count()),
        ];
    }

    /**
     * Days in a row on which BOTH friends finished a mission day. Today
     * still counts as open, so the chain runs up to today or yesterday.
     */
    public function pairStreak(User $a, User $b): int
    {
        $shared = $this->dayCompletionDates($a)->intersect($this->dayCompletionDates($b));
        $day = $a->localToday();

        if (! $shared->contains($day->toDateString())) {
            $day = $day->subDay();
        }

        $streak = 0;

        while ($shared->contains($day->toDateString())) {
            $streak++;
            $day = $day->subDay();
        }

        return $streak;
    }

    /**
     * Draws a new round from the days both have finished: mostly the newest
     * shared mission, plus some review of older ones. Null when they share
     * no finished day yet.
     */
    public function start(User $starter, User $friend): ?PairPracticeSession
    {
        $shared = $this->sharedDays($starter, $friend);

        if ($shared->isEmpty()) {
            return null;
        }

        $latest = $shared->last();
        [$latestDays, $olderDays] = $shared->partition(fn (array $day) => $day['mission']->is($latest['mission']));

        // The newest shared day's own questions come first in speaking.
        $latestSpeaking = collect($this->dayContent($latest['mission'], $latest['day'])['speaking'])->shuffle()
            ->concat($this->contentOf($latestDays->except($latest['mission']->code.'-'.$latest['day']), 'speaking')->shuffle());

        [$lowId, $highId] = $starter->id < $friend->id ? [$starter->id, $friend->id] : [$friend->id, $starter->id];

        $session = new PairPracticeSession([
            'user_a_id' => $lowId,
            'user_b_id' => $highId,
            'started_by_id' => $starter->id,
            'mission_code' => $latest['mission']->code,
            'day_number' => $latest['day'],
            'deck' => [
                PairPracticeSession::STAGE_VOCABULARY => $this->draw($this->contentOf($latestDays, 'vocabulary')->shuffle(), $this->contentOf($olderDays, 'vocabulary')->shuffle(), self::VOCABULARY_CARDS),
                PairPracticeSession::STAGE_GRAMMAR => $this->draw($this->contentOf($latestDays, 'grammar')->shuffle(), $this->contentOf($olderDays, 'grammar')->shuffle(), self::GRAMMAR_CARDS),
                PairPracticeSession::STAGE_SPEAKING => $this->draw($latestSpeaking, $this->contentOf($olderDays, 'speaking')->shuffle(), self::SPEAKING_CARDS, shuffle: false),
            ],
            'results' => [],
            'stage' => '',
        ]);

        $session->advanceStage();

        return $session;
    }

    /**
     * The cards one mission day offers: its New Words (with a Persian
     * meaning) as vocabulary cards, its grammar lesson's wrong/right
     * sentences as "say it correctly" cards, and its authored speaking
     * questions.
     *
     * @return array{vocabulary: list<array<string, mixed>>, grammar: list<array<string, mixed>>, speaking: list<array<string, mixed>>}
     */
    public function dayContent(Mission $mission, int $day): array
    {
        $phase = $mission->phases[$day - 1] ?? [];
        $source = "{$mission->code} · Day {$day}";
        $vocabulary = [];
        $grammar = [];

        foreach ($phase['steps'] ?? [] as $step) {
            if (! is_array($step)) {
                continue;
            }

            foreach ($step['words'] ?? [] as $word) {
                $persian = $word['meaning_fa'] ?? $mission->persianMeaningFor($word['phrase'] ?? '');

                if (filled($word['phrase'] ?? null) && filled($persian)) {
                    $vocabulary[] = [
                        'prompt' => $persian,
                        'answer' => $word['phrase'],
                        'meaning' => $word['meaning'] ?? null,
                        'pos' => $word['pos'] ?? null,
                        'example' => $word['example'] ?? null,
                        'source' => $source,
                    ];
                }
            }

            foreach ($step['quick_check'] ?? [] as $item) {
                if (isset($item['wrong'], $item['options'][$item['correct'] ?? -1])) {
                    $grammar[] = ['prompt' => $item['wrong'], 'answer' => $item['options'][$item['correct']], 'source' => $source];
                }
            }

            foreach ($step['lesson']['sections'] ?? [] as $section) {
                foreach ($section['blocks'] ?? [] as $block) {
                    $pairs = match ($block['type'] ?? null) {
                        'do_dont' => $block['items'] ?? [],
                        'mistake_fix' => [$block],
                        default => [],
                    };

                    foreach ($pairs as $pair) {
                        if (isset($pair['wrong'], $pair['right'])) {
                            $grammar[] = ['prompt' => $pair['wrong'], 'answer' => $pair['right'], 'source' => $source];
                        }
                    }
                }
            }
        }

        $speaking = collect($phase['pair_speaking'] ?? [])
            ->filter(fn ($card) => filled($card['question'] ?? null))
            ->map(fn (array $card) => [
                'question' => $card['question'],
                'answer' => $card['answer'] ?? '',
                'words' => array_values($card['words'] ?? []),
                'source' => $source,
            ])
            ->values()
            ->all();

        return [
            'vocabulary' => collect($vocabulary)->unique('answer')->values()->all(),
            'grammar' => collect($grammar)->unique('prompt')->values()->all(),
            'speaking' => $speaking,
        ];
    }

    /**
     * @param  Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>  $days
     * @return Collection<int, array<string, mixed>>
     */
    private function contentOf(Collection $days, string $kind): Collection
    {
        return $days->flatMap(fn (array $day) => $this->dayContent($day['mission'], $day['day'])[$kind])->values();
    }

    /**
     * $count cards, about LATEST_MISSION_SHARE of them from $latest; when
     * one pool runs short the other fills the gap.
     *
     * @param  Collection<int, array<string, mixed>>  $latest
     * @param  Collection<int, array<string, mixed>>  $older
     * @return list<array<string, mixed>>
     */
    private function draw(Collection $latest, Collection $older, int $count, bool $shuffle = true): array
    {
        $fromOlder = min($older->count(), $count - (int) ceil($count * self::LATEST_MISSION_SHARE));
        $fromLatest = min($latest->count(), $count - $fromOlder);
        $fromOlder = min($older->count(), $count - $fromLatest);

        $cards = $latest->take($fromLatest)->concat($older->take($fromOlder));

        return ($shuffle ? $cards->shuffle() : $cards)->values()->all();
    }

    /**
     * The local dates on which $user finished a mission day.
     *
     * @return Collection<int, string>
     */
    private function dayCompletionDates(User $user): Collection
    {
        $timezone = $user->reminderTimezone();

        return $this->learnedDays($user)
            ->pluck('completedAt')
            ->filter()
            ->map(fn (Carbon $completedAt) => $completedAt->copy()->setTimezone($timezone)->toDateString())
            ->unique()
            ->values();
    }
}
