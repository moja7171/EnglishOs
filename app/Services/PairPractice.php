<?php

namespace App\Services;

use App\Models\ErrorLogItem;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeNudge;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Pair Practice: which mission days two friends have BOTH
 * finished, the cards drawn from those days, each friend's progress today
 * and the pair's shared streak. A day counts once every one of its steps
 * has Evidence, so a day only one of them finished stays locked.
 */
class PairPractice
{
    public const VOCABULARY_CARDS = 8;

    public const GRAMMAR_CARDS = 8;

    public const SPEAKING_CARDS = 4;

    /**
     * Speaking cards that ask for a short talk using a few words from the
     * round's own word cards — a new mix every round, so speaking never
     * runs out. More fill in when a day has too few authored questions.
     */
    public const WORD_TALK_CARDS = 1;

    public const WORD_TALK_SIZE = 3;

    /**
     * Grammar cards per player taken from that player's own Error Log,
     * always answered by that player.
     */
    public const OWN_ERROR_CARDS = 1;

    /**
     * A card either of the two answered or judged this recently goes to
     * the back of the queue — one they got wrong comes first instead.
     */
    public const SEEN_HOURS = 24;

    /**
     * Most cards per stage either friend already had in the last
     * SEEN_HOURS (the ones they got wrong first). A mission day has only a
     * handful of grammar sentences, so a second round the same day would
     * otherwise be mostly the first one again — the stage is shorter
     * instead. A stage left with fewer than MIN_STAGE_CARDS new cards may
     * repeat up to that many.
     */
    public const MAX_REPEATS = 2;

    public const MIN_STAGE_CARDS = 3;

    /**
     * Share of each stage's cards drawn from the recent shared days; the
     * rest review older ones.
     */
    private const RECENT_SHARE = 0.75;

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
            ->keyBy(fn (array $day) => self::dayKey($day['mission'], $day['day']));
    }

    /**
     * How a mission day is named in a chosen-days list: "M03-2".
     */
    public static function dayKey(Mission $mission, int $day): string
    {
        return $mission->code.'-'.$day;
    }

    /**
     * @return Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>
     */
    public function sharedDays(User $a, User $b): Collection
    {
        return $this->learnedDays($a)->intersectByKeys($this->learnedDays($b));
    }

    /**
     * The days a game draws from: the chosen ones, otherwise every day
     * both finished. Two friends who share no day yet start from M01 Day 1.
     *
     * @param  list<string>|null  $chosen  keys like "M03-2"
     * @return Collection<string, array{mission: Mission, day: int, completedAt?: ?Carbon}>
     */
    public function gameDays(User $a, User $b, ?array $chosen = null): Collection
    {
        $shared = $this->sharedDays($a, $b);
        $picked = $chosen === null ? collect() : $shared->only($chosen);
        $days = $picked->isNotEmpty() ? $picked : $shared;

        if ($days->isEmpty() && $first = Mission::where('code', 'M01')->first()) {
            return collect(['M01-1' => ['mission' => $first, 'day' => 1]]);
        }

        return $days;
    }

    /**
     * Every day either of the two finished, newest mission first, for
     * "Choose days": only a day both finished can be picked.
     *
     * @return list<array{code: string, days: list<array{key: string, day: int, shared: bool}>}>
     */
    public function dayChoices(User $me, User $friend): array
    {
        $mine = $this->learnedDays($me);
        $theirs = $this->learnedDays($friend);
        $shared = $mine->intersectByKeys($theirs);

        return $mine->union($theirs)
            ->sortByDesc(fn (array $day) => $day['mission']->number() * 10 + $day['day'])
            ->groupBy(fn (array $day) => $day['mission']->code)
            ->map(fn ($days, string $code) => [
                'code' => $code,
                'days' => $days->sortBy('day')->map(fn (array $day) => [
                    'key' => self::dayKey($day['mission'], $day['day']),
                    'day' => $day['day'],
                    'shared' => $shared->has(self::dayKey($day['mission'], $day['day'])),
                ])->values()->all(),
            ])
            ->values()
            ->all();
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
     * How the two have practised together: finished rounds, days in a row
     * with one (up to today or yesterday), and days since the last one —
     * the "5× together · 🔥 3 days" line on Friends, chat and Practice
     * together.
     *
     * @return array{times: int, daysInRow: int, daysSince: ?int}
     */
    public function together(User $a, User $b): array
    {
        $dates = PairPracticeSession::forPair($a, $b)
            ->where('stage', PairPracticeSession::STAGE_DONE)
            ->pluck('finished_at')
            ->filter()
            ->map(fn (Carbon $at) => $at->copy()->setTimezone($a->reminderTimezone())->toDateString())
            ->unique();

        $today = $a->localToday();
        $day = $dates->contains($today->toDateString()) ? $today->copy() : $today->copy()->subDay();
        $daysInRow = 0;

        while ($dates->contains($day->toDateString())) {
            $daysInRow++;
            $day = $day->subDay();
        }

        $last = $dates->sort()->last();

        return [
            'times' => PairPracticeSession::forPair($a, $b)->where('stage', PairPracticeSession::STAGE_DONE)->count(),
            'daysInRow' => $daysInRow,
            'daysSince' => $last === null ? null : (int) Carbon::parse($last)->diffInDays($today),
        ];
    }

    /**
     * One nudge per friend per day — a bell row plus a phone push. False
     * when today's was already sent.
     */
    public function nudge(User $from, User $to): bool
    {
        if (! Cache::add(self::nudgeKey($from, $to), true, now()->endOfDay())) {
            return false;
        }

        $to->notify(new PairPracticeNudge($from));

        return true;
    }

    public static function nudgeKey(User $from, User $to): string
    {
        return 'pair-nudge:'.$from->id.':'.$to->id.':'.now()->toDateString();
    }

    /**
     * Days in a row on which BOTH friends kept their own streak (the same
     * daily ring rule, see StreakRing). Today still counts as open, so the
     * chain runs up to today or yesterday.
     */
    public function pairStreak(User $a, User $b): int
    {
        $shared = collect($a->streakRing()['keptDates'])->intersect($b->streakRing()['keptDates']);
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
     * Draws a new round, waiting for the friend to join. With $days (keys
     * like "M01-2") the cards come from those shared days only, taken in
     * turn so each day gets its share. Without, recent days come first —
     * the newest shared day, then the rest of its mission and every day
     * finished since the pair last practised — plus some review of older
     * ones. Either way cards either of the two saw in the last day go to
     * the back and the ones they missed come first; each player gets a
     * sentence from their own Error Log, and speaking adds "talk using
     * these words" cards. The round ends with a game — the one the two
     * have played least lately. Null when they share no finished day yet.
     *
     * @param  list<string>|null  $days
     */
    public function start(User $starter, User $friend, ?array $days = null): ?PairPracticeSession
    {
        $shared = $this->sharedDays($starter, $friend);

        if ($shared->isEmpty()) {
            return null;
        }

        ['deck' => $deck, 'latest' => $latest, 'chosen' => $chosen] = $this->drawCards($starter, $friend, $shared, $days);
        $deck['game_pick'] = app(PairGames::class)->practiceOrder($starter, $friend)[0];

        [$lowId, $highId] = $starter->id < $friend->id ? [$starter->id, $friend->id] : [$friend->id, $starter->id];

        return PairPracticeSession::create([
            'user_a_id' => $lowId,
            'user_b_id' => $highId,
            'started_by_id' => $starter->id,
            'mission_code' => $latest['mission']->code,
            'day_number' => $latest['day'],
            'days' => $chosen->isNotEmpty() ? $chosen->keys()->values()->all() : null,
            'deck' => $deck,
            'results' => [],
            'stage' => PairPracticeSession::STAGE_WAITING,
        ]);
    }

    /**
     * The cards of a round with the AI, drawn the same way from the days
     * the learner finished. Null with no finished day.
     *
     * @param  list<string>|null  $days
     * @return array{cards: array<string, list<array<string, mixed>>>, days: list<string>|null}|null
     */
    public function soloDeck(User $learner, ?array $days = null): ?array
    {
        $learned = $this->learnedDays($learner);

        if ($learned->isEmpty()) {
            return null;
        }

        ['deck' => $deck, 'chosen' => $chosen] = $this->drawCards($learner, $learner, $learned, $days);

        return ['cards' => $deck, 'days' => $chosen->isNotEmpty() ? $chosen->keys()->values()->all() : null];
    }

    /**
     * The word, grammar and speaking cards of a round.
     *
     * @param  Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>  $shared
     * @param  list<string>|null  $days
     * @return array{deck: array<string, list<array<string, mixed>>>, latest: array{mission: Mission, day: int}, chosen: Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>}
     */
    private function drawCards(User $starter, User $friend, Collection $shared, ?array $days): array
    {
        $chosen = $days === null ? collect() : $shared->only($days);
        $history = $this->recentHistory($starter, $friend);
        $authoredSpeaking = self::SPEAKING_CARDS - self::WORD_TALK_CARDS;

        if ($chosen->isNotEmpty()) {
            $latest = $chosen->last();
            $deck = [
                PairPracticeSession::STAGE_VOCABULARY => $this->drawInTurn($chosen, 'vocabulary', self::VOCABULARY_CARDS, $history),
                PairPracticeSession::STAGE_GRAMMAR => $this->drawInTurn($chosen, 'grammar', self::GRAMMAR_CARDS, $history),
                PairPracticeSession::STAGE_SPEAKING => $this->drawInTurn($chosen, 'speaking', $authoredSpeaking, $history, shuffle: false),
            ];
        } else {
            $latest = $shared->last();
            $latestKey = self::dayKey($latest['mission'], $latest['day']);
            $positions = $shared->keys()->flip();
            $lastPractised = PairPracticeSession::forPair($starter, $friend)->whereNotNull('finished_at')->latest('finished_at')->first();
            $lastPosition = $lastPractised ? $positions->get($lastPractised->mission_code.'-'.$lastPractised->day_number) : null;

            [$recentDays, $olderDays] = $shared->partition(fn (array $day, string $key) => $day['mission']->is($latest['mission'])
                || ($lastPosition !== null && $positions[$key] > $lastPosition));

            $deck = [
                PairPracticeSession::STAGE_VOCABULARY => $this->draw($this->prioritize($this->newestDayFirst($recentDays, $latestKey, 'vocabulary'), $history), $this->prioritize($this->contentOf($olderDays, 'vocabulary')->shuffle(), $history), self::VOCABULARY_CARDS, $history),
                PairPracticeSession::STAGE_GRAMMAR => $this->draw($this->prioritize($this->newestDayFirst($recentDays, $latestKey, 'grammar'), $history), $this->prioritize($this->contentOf($olderDays, 'grammar')->shuffle(), $history), self::GRAMMAR_CARDS, $history),
                PairPracticeSession::STAGE_SPEAKING => $this->draw($this->prioritize($this->newestDayFirst($recentDays, $latestKey, 'speaking'), $history), $this->prioritize($this->contentOf($olderDays, 'speaking')->shuffle(), $history), $authoredSpeaking, $history, shuffle: false),
            ];
        }

        $deck[PairPracticeSession::STAGE_GRAMMAR] = $this->withOwnErrors($deck[PairPracticeSession::STAGE_GRAMMAR], collect([$starter, $friend])->unique('id')->all(), $chosen->isNotEmpty() ? $chosen : $shared, $history);
        $deck[PairPracticeSession::STAGE_SPEAKING] = $this->withWordTalks($deck[PairPracticeSession::STAGE_SPEAKING], $deck[PairPracticeSession::STAGE_VOCABULARY]);

        return ['deck' => $deck, 'latest' => $latest, 'chosen' => $chosen];
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
                        'mission' => $mission->code,
                        'key' => 'v:'.mb_strtolower($word['phrase']),
                    ];
                }
            }

            foreach ($step['quick_check'] ?? [] as $item) {
                if (isset($item['wrong'], $item['options'][$item['correct'] ?? -1])) {
                    $grammar[] = ['prompt' => $item['wrong'], 'answer' => $item['options'][$item['correct']], 'source' => $source, 'mission' => $mission->code, 'key' => 'g:'.$item['wrong']];
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
                            $grammar[] = ['prompt' => $pair['wrong'], 'answer' => $pair['right'], 'source' => $source, 'mission' => $mission->code, 'key' => 'g:'.$pair['wrong']];
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
                'mission' => $mission->code,
                'key' => 's:'.$card['question'],
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
     * The newest shared day's cards (shuffled) ahead of the other recent
     * days' cards (shuffled), so a short draw still covers the newest day.
     *
     * @param  Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>  $recentDays
     * @return Collection<int, array<string, mixed>>
     */
    private function newestDayFirst(Collection $recentDays, string $latestKey, string $kind): Collection
    {
        return $this->contentOf($recentDays->only($latestKey), $kind)->shuffle()
            ->concat($this->contentOf($recentDays->except($latestKey), $kind)->shuffle());
    }

    /**
     * $count cards, about RECENT_SHARE of them from $recent (taken from its
     * front); when one pool runs short the other fills the gap. Cards the
     * two have not had lately come first; at most repeatsAllowed() of the
     * others.
     *
     * @param  Collection<int, array<string, mixed>>  $recent
     * @param  Collection<int, array<string, mixed>>  $older
     * @param  array{seen: array<string, true>, missed: array<string, true>}  $history
     * @return list<array<string, mixed>>
     */
    private function draw(Collection $recent, Collection $older, int $count, array $history, bool $shuffle = true): array
    {
        [$recentRepeats, $recentFresh] = $recent->partition(fn (array $card) => $this->wasSeen($card, $history));
        [$olderRepeats, $olderFresh] = $older->partition(fn (array $card) => $this->wasSeen($card, $history));

        $fresh = $this->takeShares($recentFresh->values(), $olderFresh->values(), $count);
        $repeats = $this->takeShares($recentRepeats->values(), $olderRepeats->values(), $this->repeatsAllowed($fresh->count(), $count));
        $cards = $fresh->concat($repeats);

        return ($shuffle ? $cards->shuffle() : $cards)->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $recent
     * @param  Collection<int, array<string, mixed>>  $older
     * @return Collection<int, array<string, mixed>>
     */
    private function takeShares(Collection $recent, Collection $older, int $count): Collection
    {
        $fromOlder = min($older->count(), $count - (int) ceil($count * self::RECENT_SHARE));
        $fromRecent = min($recent->count(), $count - $fromOlder);
        $fromOlder = min($older->count(), $count - $fromRecent);

        return $recent->take($fromRecent)->concat($older->take($fromOlder))->values();
    }

    /**
     * How many already-seen cards may join $fresh new ones in a stage of
     * $count: MAX_REPEATS, or enough to reach MIN_STAGE_CARDS.
     */
    private function repeatsAllowed(int $fresh, int $count): int
    {
        return max(0, min($count - $fresh, max(self::MAX_REPEATS, self::MIN_STAGE_CARDS - $fresh)));
    }

    /**
     * @param  array<string, mixed>  $card
     * @param  array{seen: array<string, true>, missed: array<string, true>}  $history
     */
    private function wasSeen(array $card, array $history): bool
    {
        return isset($history['seen'][$card['key'] ?? '']);
    }

    /**
     * Cards from the chosen days taken one day at a time in turn, so every
     * day gets its share however many are picked; a day that runs out
     * leaves its turn to the others. New cards first, then at most
     * repeatsAllowed() already-seen ones.
     *
     * @param  Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>  $days
     * @param  array{seen: array<string, true>, missed: array<string, true>}  $history
     * @return list<array<string, mixed>>
     */
    private function drawInTurn(Collection $days, string $kind, int $count, array $history, bool $shuffle = true): array
    {
        $pools = $days->map(fn (array $day) => $this->prioritize(collect($this->dayContent($day['mission'], $day['day'])[$kind])->shuffle(), $history))->values();

        $fresh = $this->inTurn($pools->map(fn (Collection $pool) => $pool->reject(fn (array $card) => $this->wasSeen($card, $history))->values()->all())->all(), $count);
        $repeats = $this->inTurn($pools->map(fn (Collection $pool) => $pool->filter(fn (array $card) => $this->wasSeen($card, $history))->values()->all())->all(), $this->repeatsAllowed(count($fresh), $count));
        $cards = [...$fresh, ...$repeats];

        return $shuffle ? collect($cards)->shuffle()->values()->all() : $cards;
    }

    /**
     * Up to $count cards, one from each pool in turn.
     *
     * @param  list<list<array<string, mixed>>>  $pools
     * @return list<array<string, mixed>>
     */
    private function inTurn(array $pools, int $count): array
    {
        $cards = [];

        while (count($cards) < $count && array_filter($pools) !== []) {
            foreach ($pools as &$pool) {
                if ($pool !== [] && count($cards) < $count) {
                    $cards[] = array_shift($pool);
                }
            }
            unset($pool);
        }

        return $cards;
    }

    /**
     * Cards either of the two missed lately first, then ones neither has
     * seen, then ones seen in the last SEEN_HOURS — keeping the order
     * within each group.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @param  array{seen: array<string, true>, missed: array<string, true>}  $history
     * @return Collection<int, array<string, mixed>>
     */
    private function prioritize(Collection $cards, array $history): Collection
    {
        return $cards->sortBy(fn (array $card) => match (true) {
            isset($history['missed'][$card['key'] ?? '']) => 0,
            isset($history['seen'][$card['key'] ?? '']) => 2,
            default => 1,
        })->values();
    }

    /**
     * Which cards either friend answered or judged in any round (with
     * anyone) in the last SEEN_HOURS, and which of those one of the two
     * last got wrong.
     *
     * @return array{seen: array<string, true>, missed: array<string, true>}
     */
    private function recentHistory(User $a, User $b): array
    {
        $ids = [$a->id, $b->id];
        $seen = [];
        $missed = [];

        $sessions = PairPracticeSession::query()
            ->where(fn ($query) => $query->whereIn('user_a_id', $ids)->orWhereIn('user_b_id', $ids))
            ->where('created_at', '>=', now()->subHours(self::SEEN_HOURS))
            ->oldest()
            ->get();

        foreach ($sessions as $session) {
            foreach ($session->results ?? [] as $result) {
                $key = $session->deck[$result['stage']][$result['card']]['key'] ?? null;

                if ($key === null) {
                    continue;
                }

                $seen[$key] = true;

                if (in_array($result['speaker_id'], $ids, true)) {
                    if ($result['correct'] === false) {
                        $missed[$key] = true;
                    } else {
                        unset($missed[$key]);
                    }
                }
            }
        }

        return ['seen' => $seen, 'missed' => $missed];
    }

    /**
     * Swaps OWN_ERROR_CARDS grammar cards per player for sentences from
     * that player's own Error Log on the round's missions — always theirs
     * to answer.
     *
     * @param  list<array<string, mixed>>  $grammar
     * @param  list<User>  $players
     * @param  Collection<string, array{mission: Mission, day: int, completedAt: ?Carbon}>  $days
     * @param  array{seen: array<string, true>, missed: array<string, true>}  $history
     * @return list<array<string, mixed>>
     */
    private function withOwnErrors(array $grammar, array $players, Collection $days, array $history): array
    {
        $missionIds = $days->map(fn (array $day) => $day['mission']->id)->unique()->values();

        $own = collect($players)->flatMap(fn (User $player) => $this->prioritize(
            ErrorLogItem::query()
                ->with('missionRun.mission')
                ->whereHas('missionRun', fn ($query) => $query->where('learner_id', $player->id)->whereIn('mission_id', $missionIds))
                ->get()
                ->filter(fn (ErrorLogItem $item) => filled($item->error) && filled($item->correction))
                ->shuffle()
                ->map(fn (ErrorLogItem $item) => [
                    'prompt' => $item->error,
                    'answer' => $item->correction,
                    'source' => $item->missionRun->mission->code.' · Error Log',
                    'mission' => $item->missionRun->mission->code,
                    'key' => 'e:'.$item->id,
                    'owner_id' => $player->id,
                ])
                ->values(),
            $history,
        )->take(self::OWN_ERROR_CARDS));

        if ($own->isEmpty()) {
            return $grammar;
        }

        return collect($grammar)
            ->reject(fn (array $card) => $own->contains('prompt', $card['prompt']))
            ->take(self::GRAMMAR_CARDS - $own->count())
            ->concat($own)
            ->shuffle()
            ->values()
            ->all();
    }

    /**
     * Adds "talk using these words" cards made from the round's word
     * cards, one after every two questions — WORD_TALK_CARDS of them, or
     * more when the days have too few authored questions.
     *
     * @param  list<array<string, mixed>>  $speaking
     * @param  list<array<string, mixed>>  $vocabulary
     * @return list<array<string, mixed>>
     */
    private function withWordTalks(array $speaking, array $vocabulary): array
    {
        $wanted = max(self::WORD_TALK_CARDS, self::SPEAKING_CARDS - count($speaking));

        $talks = collect($vocabulary)->pluck('answer')->unique()->shuffle()
            ->chunk(self::WORD_TALK_SIZE)
            ->filter(fn (Collection $words) => $words->count() === self::WORD_TALK_SIZE)
            ->take($wanted)
            ->map(fn (Collection $words) => [
                'type' => 'talk',
                'question' => 'Talk using these words',
                'answer' => '',
                'words' => $words->values()->all(),
                'source' => 'Words from this round',
            ])
            ->values();

        $cards = [];

        foreach ($speaking as $index => $card) {
            $cards[] = $card;

            if ($index % 2 === 1 && $talks->isNotEmpty()) {
                $cards[] = $talks->shift();
            }
        }

        return [...$cards, ...$talks->all()];
    }
}
