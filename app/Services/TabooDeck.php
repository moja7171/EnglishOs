<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\PairGame;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Taboo on the Games page: the rules for a pair (how long a turn is, how
 * many skips, whether bonus words are on) and the cards. The words come
 * from the mission days both friends finished and from both their
 * notebooks (My Words); words the two played in the last day go to the
 * back. Bonus words — two words the explainer may try not to say — are
 * written in advance for mission words (config/taboo_bonus.php) and
 * asked of the AI once, then cached, for notebook words.
 */
class TabooDeck
{
    /** Up to M04 a turn is longer and skipping is free. */
    public const BEGINNER_UNTIL_MISSION = 4;

    public const BEGINNER_TURN_SECONDS = 90;

    public const TURN_SECONDS = 60;

    public const SKIPS_PER_TURN = 2;

    /** Cards drawn per planned turn — more than anyone gets through. */
    private const CARDS_PER_TURN = 14;

    /** A notebook entry longer than this is a sentence, not a Taboo word. */
    private const MAX_WORDS_IN_CARD = 4;

    /** Sentence starters under the card, fewer as the learners get better. */
    public const STARTERS = ['It’s a…', 'You use it to…', 'It’s like…'];

    public function __construct(private readonly PairPractice $pairPractice) {}

    /**
     * The lower of the two players' missions sets the level, so the game
     * is never harder than the newer learner can handle.
     *
     * @return array{turn_seconds: int, turns: int, skips: ?int, bonus: bool, starters: int, level: int}
     */
    public function rulesFor(User $a, User $b, int $minutes): array
    {
        $level = min($this->missionNumberOf($a), $this->missionNumberOf($b));
        $beginner = $level <= self::BEGINNER_UNTIL_MISSION;
        $turnSeconds = $beginner ? self::BEGINNER_TURN_SECONDS : self::TURN_SECONDS;

        return [
            'turn_seconds' => $turnSeconds,
            'turns' => max(2, 2 * (int) round($minutes * 60 / $turnSeconds / 2)),
            'skips' => $beginner ? null : self::SKIPS_PER_TURN,
            'bonus' => ! $beginner,
            'starters' => match (true) {
                $beginner => 3,
                $level <= 8 => 2,
                default => 1,
            },
            'level' => $level,
        ];
    }

    /**
     * The mission the learner is on — or the last one they finished.
     */
    public function missionNumberOf(User $user): int
    {
        $current = $user->currentInProgressMissionRun()?->mission;

        if ($current) {
            return $current->number();
        }

        $finished = $this->pairPractice->learnedDays($user)->last();

        return $finished ? $finished['mission']->number() : 1;
    }

    /**
     * The cards: shared mission words and both notebooks, mixed, the
     * pair's recently played words last. Chosen days mean only those
     * days' words.
     *
     * @param  array{turns: int, bonus: bool, days?: ?list<string>}  $rules
     * @return list<array{word: string, source: string, bonus: list<string>, key: string}>
     */
    public function cardsFor(User $a, User $b, array $rules): array
    {
        $recent = $this->recentlyPlayed($a, $b);
        $notebooks = ($rules['days'] ?? null) === null ? $this->notebookWords($a)->concat($this->notebookWords($b)) : collect();
        $words = $this->missionWords($a, $b, $rules['days'] ?? null)->concat($notebooks)
            ->unique('key')
            ->shuffle()
            ->sortBy(fn (array $card) => isset($recent[$card['key']]) ? 1 : 0)
            ->take($rules['turns'] * self::CARDS_PER_TURN)
            ->values();

        $bonus = $rules['bonus'] ? $this->bonusWordsFor($words->pluck('word')) : [];

        return $words->map(fn (array $card) => [
            'word' => $card['word'],
            'source' => $card['source'],
            'bonus' => $bonus[mb_strtolower($card['word'])] ?? [],
            'key' => $card['key'],
        ])->all();
    }

    /**
     * Words of the days the game draws from (see PairPractice::gameDays()).
     *
     * @param  list<string>|null  $chosen
     * @return Collection<int, array{word: string, source: string, key: string}>
     */
    private function missionWords(User $a, User $b, ?array $chosen): Collection
    {
        $days = $this->pairPractice->gameDays($a, $b, $chosen);

        return $days->flatMap(fn (array $day) => collect($this->pairPractice->dayContent($day['mission'], $day['day'])['vocabulary'])
            ->map(fn (array $card) => [
                'word' => $card['answer'],
                'source' => $day['mission']->code.' · '.$day['mission']->title,
                'key' => 'w:'.mb_strtolower($card['answer']),
            ]))
            ->values();
    }

    /**
     * @return Collection<int, array{word: string, source: string, key: string}>
     */
    private function notebookWords(User $user): Collection
    {
        return $user->vocabularyWords()->pluck('word')
            ->map(fn ($word) => trim((string) $word))
            ->filter(fn (string $word) => $word !== '' && str_word_count($word) <= self::MAX_WORDS_IN_CARD)
            ->map(fn (string $word) => [
                'word' => $word,
                'source' => 'Notebook',
                'key' => 'w:'.mb_strtolower($word),
            ])
            ->values();
    }

    /**
     * Card keys the two played (in any game together) in the last day.
     *
     * @return array<string, true>
     */
    private function recentlyPlayed(User $a, User $b): array
    {
        return PairGame::forPair($a, $b)
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->flatMap(fn (PairGame $game) => collect($game->turns ?? [])
                ->flatMap(fn (array $turn) => collect($turn['cards'])->map(fn (array $card) => $game->deck[$card['card']]['key'] ?? null)))
            ->filter()
            ->mapWithKeys(fn (string $key) => [$key => true])
            ->all();
    }

    /**
     * Bonus words by lower-case word: written ones first, then ones the AI
     * gave before. Words with neither are asked of the AI after the
     * response is sent, ready for the next game.
     *
     * @param  Collection<int, string>  $words
     * @return array<string, list<string>>
     */
    public function bonusWordsFor(Collection $words): array
    {
        $written = config('taboo_bonus', []);
        $found = [];
        $missing = [];

        foreach ($words as $word) {
            $key = mb_strtolower($word);
            $pair = $written[$key] ?? Cache::get(self::cacheKey($key));

            if (is_array($pair) && $pair !== []) {
                $found[$key] = array_values($pair);
            } else {
                $missing[] = $word;
            }
        }

        if ($missing !== []) {
            dispatch(fn () => app(self::class)->askForBonusWords($missing))->afterResponse();
        }

        return $found;
    }

    /**
     * One AI call for a batch of words; each answer is cached forever. A
     * failed call leaves the words without bonus words (tried again next
     * game).
     *
     * @param  list<string>  $words
     */
    public function askForBonusWords(array $words): void
    {
        $words = array_slice(array_values(array_unique($words)), 0, 40);

        try {
            $reply = app(GeminiClient::class)->chat(
                [['role' => 'user', 'text' => json_encode($words, JSON_UNESCAPED_UNICODE)]],
                'You help make Taboo cards for B1 English learners. For each English word or phrase in the JSON list, give the TWO simple everyday English words a learner would most likely say to explain it (not the word itself, not part of it). Reply with JSON only: {"<word>": ["word1", "word2"], ...}.',
                maxOutputTokens: 1500,
            );
        } catch (Throwable) {
            return;
        }

        $answer = json_decode((string) Str::of($reply)->after('{')->beforeLast('}')->prepend('{')->append('}'), true);

        if (! is_array($answer)) {
            return;
        }

        foreach ($answer as $word => $pair) {
            if (is_string($word) && is_array($pair)) {
                $pair = collect($pair)->filter(fn ($bonus) => is_string($bonus) && $bonus !== '')->take(2)->values()->all();

                if (count($pair) === 2) {
                    Cache::forever(self::cacheKey(mb_strtolower($word)), $pair);
                }
            }
        }
    }

    private static function cacheKey(string $word): string
    {
        return 'taboo-bonus:'.md5($word);
    }
}
