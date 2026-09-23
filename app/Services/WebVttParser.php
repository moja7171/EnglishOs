<?php

namespace App\Services;

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
}
