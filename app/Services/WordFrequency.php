<?php

namespace App\Services;

/**
 * How common an English word is, from a list of the 10,000 words most used
 * in film and TV subtitles (everyday speech — resources/data/word-frequency.txt,
 * most used first, the first 10,000 usable words of the OpenSubtitles list of hermitdave/FrequencyWords, CC BY-SA 4.0).
 * A word out of the list is rare for a learner. It says nothing about
 * phrases, which are rated by Gemini (see GeminiWordDefiner::commonness()).
 */
class WordFrequency
{
    public const RARE = 1;

    public const COMMON = 2;

    public const VERY_COMMON = 3;

    /** Ranks up to these are VERY_COMMON and COMMON; the rest are RARE. */
    private const VERY_COMMON_UP_TO = 3000;

    private const COMMON_UP_TO = 9000;

    /** @var array<string, int>|null word => rank (1 = most used) */
    private static ?array $ranks = null;

    /**
     * RARE, COMMON or VERY_COMMON for a single word, judged by the word
     * itself, or when the list does not have it by the word it is a form of
     * ("running" → "run"). null for a phrase.
     */
    public function levelOf(string $word): ?int
    {
        if (str_contains($word, ' ')) {
            return null;
        }

        $ranks = self::ranks();
        $best = $ranks[$word] ?? collect($this->candidates($word))
            ->map(fn (string $candidate) => $ranks[$candidate] ?? null)
            ->filter()
            ->min();

        return match (true) {
            $best === null => self::RARE,
            $best <= self::VERY_COMMON_UP_TO => self::VERY_COMMON,
            $best <= self::COMMON_UP_TO => self::COMMON,
            default => self::RARE,
        };
    }

    /**
     * @return list<string>
     */
    private function candidates(string $word): array
    {
        $candidates = [$word];

        if (str_ends_with($word, 'ies')) {
            $candidates[] = substr($word, 0, -3).'y';
        }

        foreach (['es', 's', 'ed', 'ing', 'er', 'ly'] as $ending) {
            if (str_ends_with($word, $ending) && strlen($word) > strlen($ending) + 2) {
                $stem = substr($word, 0, -strlen($ending));
                $candidates[] = $stem;
                $candidates[] = $stem.'e';

                if (strlen($stem) > 3 && $stem[-1] === $stem[-2]) {
                    $candidates[] = substr($stem, 0, -1);
                }
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, int>
     */
    private static function ranks(): array
    {
        if (self::$ranks === null) {
            $words = file(resource_path('data/word-frequency.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::$ranks = array_flip($words);

            foreach (self::$ranks as $word => $index) {
                self::$ranks[$word] = $index + 1;
            }
        }

        return self::$ranks;
    }
}
