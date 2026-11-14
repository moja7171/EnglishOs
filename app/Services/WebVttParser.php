<?php

namespace App\Services;

use Closure;

/**
 * Minimal WebVTT cue reader — just enough to feed real caption timing
 * into ShadowLineTimestampMatcher for Video Shadowing (see
 * missions:cache-shadow-timestamps). Ignores cue settings (line:80% and
 * similar), NOTE blocks, and cue identifiers; only text + timing.
 */
class WebVttParser
{
    /**
     * @return list<array{text: string, start: float, end: float}>
     */
    public static function parse(string $vtt): array
    {
        $vtt = str_replace("\r\n", "\n", $vtt);
        $blocks = preg_split('/\n{2,}/', trim($vtt));
        $cues = [];

        foreach ($blocks as $block) {
            $lines = array_values(array_filter(explode("\n", trim($block)), fn ($line) => $line !== ''));

            if ($lines === [] || $lines[0] === 'WEBVTT') {
                continue;
            }

            // A cue identifier (a bare number/string) sits on its own
            // line before the timing line — skip past it if present.
            $timingIndex = str_contains($lines[0], '-->') ? 0 : 1;

            if (! isset($lines[$timingIndex]) || ! str_contains($lines[$timingIndex], '-->')) {
                continue;
            }

            [$startRaw, $endRaw] = array_map('trim', explode('-->', $lines[$timingIndex], 2));
            $text = trim(implode(' ', array_slice($lines, $timingIndex + 1)));

            if ($text === '') {
                continue;
            }

            $cues[] = [
                'text' => $text,
                'start' => self::toSeconds($startRaw),
                'end' => self::toSeconds($endRaw),
            ];
        }

        return $cues;
    }

    /**
     * @param  string  $timecode  "HH:MM:SS.mmm" (or "MM:SS.mmm"), optionally
     *                            followed by cue settings like "line:80%" — only the timecode
     *                            itself, at the start of the string, is parsed.
     */
    private static function toSeconds(string $timecode): float
    {
        $timecode = strtok($timecode, ' ');
        $parts = array_map('floatval', explode(':', $timecode));

        return match (count($parts)) {
            3 => $parts[0] * 3600 + $parts[1] * 60 + $parts[2],
            2 => $parts[0] * 60 + $parts[1],
            default => 0.0,
        };
    }

    /**
     * Groups timed caption chunks into sentences, for a transcript that
     * reads as prose: a chunk often stops mid-sentence ("…conversations
     * in" / "easy English…"), so a new group starts only after a chunk
     * that ends a sentence.
     *
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @param  Closure(TItem): string  $text
     * @return list<list<TItem>>
     */
    public static function sentences(array $items, Closure $text): array
    {
        $groups = [[]];

        foreach ($items as $item) {
            $groups[array_key_last($groups)][] = $item;

            if (preg_match('/[.?!…]["”’)]*$/u', trim($text($item)))) {
                $groups[] = [];
            }
        }

        return array_values(array_filter($groups));
    }

    /**
     * Whether any chunk carries its sentence's Persian line ("fa", see
     * scripts/translate-fa.py), so a player knows to offer the translation.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    public static function hasTranslation(array $segments): bool
    {
        foreach ($segments as $segment) {
            if (filled($segment['fa'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Persian line of one sentence group as returned by sentences()
     * (it sits on the group's first chunk).
     *
     * @param  list<array{index: int, segment: array<string, mixed>}>  $sentence
     */
    public static function translation(array $sentence): ?string
    {
        foreach ($sentence as $line) {
            if (filled($line['segment']['fa'] ?? null)) {
                return $line['segment']['fa'];
            }
        }

        return null;
    }

    /**
     * The Persian lines of every sentence that overlaps the time window
     * [$start, $end] (the span of a shadowing line), joined; null if none.
     * A chunk without its own "fa" belongs to the sentence started before it.
     *
     * @param  list<array<string, mixed>>  $segments
     */
    public static function translationWithin(array $segments, float $start, float $end): ?string
    {
        $lines = [];
        $current = null;

        foreach ($segments as $segment) {
            if (filled($segment['fa'] ?? null)) {
                $current = $segment['fa'];
            }

            if ($current !== null && (float) $segment['end'] > $start && (float) $segment['start'] < $end) {
                $lines[$current] = $current;
            }
        }

        return $lines === [] ? null : implode(' ', $lines);
    }
}
