<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Just a Minute on the Games page: one talks about a topic of a mission
 * both friends have reached (config/just_a_minute.php), trying to use
 * four of that mission's words; the listener ticks each word they hear,
 * then asks one question. Up to M04 the talk is 45 seconds, then a full
 * minute.
 */
class JustAMinuteDeck
{
    public const BEGINNER_TALK_SECONDS = 45;

    public const TALK_SECONDS = 60;

    public const TARGET_WORDS = 4;

    public function __construct(private readonly PairPractice $pairPractice, private readonly TabooDeck $taboo) {}

    /**
     * @return array{turn_seconds: int, turns: int, skips: null, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        $level = min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b));
        $talk = $level <= TabooDeck::BEGINNER_UNTIL_MISSION ? self::BEGINNER_TALK_SECONDS : self::TALK_SECONDS;

        return [
            'turn_seconds' => $talk,
            'turns' => max(2, 2 * (int) round($minutes * 60 / ($talk + PairGame::ASK_SECONDS) / 2)),
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => $level,
        ];
    }

    /**
     * One card per topic: the topic and four words of its mission (from
     * the days both finished when there are enough), topics played in the
     * last day last.
     *
     * @param  array{turns: int}  $rules
     * @return list<array{word: string, words: list<string>, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $days = $this->pairPractice->sharedDays($a, $b);

        if ($days->isEmpty()) {
            $days = $this->pairPractice->learnedDays($a)->union($this->pairPractice->learnedDays($b));
        }

        if ($days->isEmpty() && $first = Mission::where('code', 'M01')->first()) {
            $days = collect(['M01-1' => ['mission' => $first, 'day' => 1]]);
        }

        $recent = PairGame::forPair($a, $b)->where('game', PairGame::GAME_MINUTE)->where('created_at', '>=', now()->subDay())->get()
            ->flatMap(fn (PairGame $game) => collect($game->playedCards())->pluck('word'))
            ->flip();

        return $days->groupBy(fn (array $day) => $day['mission']->code)
            ->flatMap(function (Collection $missionDays, string $code) {
                $mission = $missionDays->first()['mission'];
                $words = $missionDays->flatMap(fn (array $day) => collect($this->pairPractice->dayContent($day['mission'], $day['day'])['vocabulary'])->pluck('answer'))->unique()->values();

                return collect(config('just_a_minute.'.$code, []))->map(fn (string $topic) => [
                    'word' => $topic,
                    'words' => $words->shuffle()->take(self::TARGET_WORDS)->values()->all(),
                    'source' => $code.' · '.$mission->title,
                    'bonus' => [],
                    'key' => 'jam:'.$topic,
                ]);
            })
            ->filter(fn (array $card) => count($card['words']) >= 2)
            ->shuffle()
            ->sortBy(fn (array $card) => $recent->has($card['word']) ? 1 : 0)
            ->take($rules['turns'] * 2)
            ->values()
            ->all();
    }
}
