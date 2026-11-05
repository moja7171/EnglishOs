<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\PairGame;
use App\Models\User;

/**
 * Story Chain on the Games page: one story per 5 minutes, told together
 * a sentence or two at a time. Each story belongs to a mission both
 * friends reached: its photo, a first line in that mission's grammar
 * (config/story_chain.php) and one of its words per turn. Up to M04 a turn
 * is 40 seconds, then 30.
 */
class StoryChainDeck
{
    public const BEGINNER_TURN_SECONDS = 40;

    public const TURN_SECONDS = 30;

    public const STORY_TURNS = 8;

    public function __construct(private readonly PairPractice $pairPractice, private readonly TabooDeck $taboo, private readonly PexelsClient $pexels) {}

    /**
     * @return array{turn_seconds: int, turns: int, story_turns: int, skips: null, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        $level = min($this->taboo->missionNumberOf($a), $this->taboo->missionNumberOf($b));

        return [
            'turn_seconds' => $level <= TabooDeck::BEGINNER_UNTIL_MISSION ? self::BEGINNER_TURN_SECONDS : self::TURN_SECONDS,
            'turns' => self::STORY_TURNS * ($minutes >= 10 ? 2 : 1),
            'story_turns' => self::STORY_TURNS,
            'skips' => null,
            'bonus' => false,
            'starters' => 0,
            'level' => $level,
        ];
    }

    /**
     * One card per turn, in order: each carries its story's first line,
     * photo and mission, and that turn's word. Stories come from the
     * pair's newest shared missions first; the lines they told in the last
     * day are left out when there are others.
     *
     * @param  array{turns: int, story_turns: int}  $rules
     * @return list<array{word: string, story: int, line: string, image: ?string, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $days = $this->pairPractice->gameDays($a, $b, $rules['days'] ?? null);

        $told = PairGame::forPair($a, $b)->where('game', PairGame::GAME_STORY)->where('created_at', '>=', now()->subDay())->get()
            ->flatMap(fn (PairGame $game) => collect($game->deck)->pluck('line'))
            ->flip();

        $missions = $days->groupBy(fn (array $day) => $day['mission']->code)
            ->map(fn ($missionDays) => [
                'mission' => $missionDays->first()['mission'],
                'words' => $missionDays->flatMap(fn (array $day) => collect($this->pairPractice->dayContent($day['mission'], $day['day'])['vocabulary'])->pluck('answer'))->unique()->values(),
            ])
            ->filter(fn (array $entry) => filled(config('story_chain.'.$entry['mission']->code)) && $entry['words']->count() >= 4)
            ->sortByDesc(fn (array $entry) => $entry['mission']->number())
            ->values();

        $stories = intdiv($rules['turns'], $rules['story_turns']);
        $cards = [];

        for ($story = 0; $story < $stories && $missions->isNotEmpty(); $story++) {
            $entry = $missions[$story % $missions->count()];
            $mission = $entry['mission'];
            $lines = collect(config('story_chain.'.$mission->code))->shuffle();
            $line = $lines->first(fn (string $line) => ! $told->has($line) && ! in_array($line, array_column($cards, 'line'), true)) ?? $lines->first();
            $words = $entry['words']->shuffle();

            for ($turn = 0; $turn < $rules['story_turns']; $turn++) {
                $cards[] = [
                    'word' => $words[$turn % $words->count()],
                    'story' => $story,
                    'line' => $line,
                    'image' => $this->pexels->cachedImageUrl($mission->code.'-brief'),
                    'source' => $mission->code.' · '.$mission->title,
                    'bonus' => [],
                    'key' => 'story:'.$mission->code.':'.$turn,
                ];
            }
        }

        return $cards;
    }
}
