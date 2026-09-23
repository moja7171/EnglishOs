<?php

namespace App\Services;

/**
 * Locates a seeded line (a shadow_lines phrase, or a full transcript
 * line — may carry **bold** stress markers) inside a mission audio's
 * real Whisper segments, and returns the real start/end time (seconds)
 * — see missions:cache-shadow-timestamps, the only caller, which runs
 * this once per mission and writes the result to a checked-in JSON
 * cache (document/{code}/shadow_timestamps.json), the same "generate
 * once, never live" pattern as PexelsClient's warmed image cache.
 *
 * Whisper's own segments aren't sentence-clean — a segment can merge
 * several short sentences into one long span (confirmed against the
 * real M01 audio: one 16-second segment held 4 sentences). A line that
 * starts mid-segment would get a pause timed to the START of that
 * whole span, not the words actually being said. This estimates a
 * position PROPORTIONALLY within the matched segment(s) by character
 * offset, assuming roughly constant speaking pace inside a segment —
 * exact when a line matches a segment 1:1 (the common case), an
 * estimate otherwise, never the wildly-wrong "start of an unrelated
 * multi-sentence span" a naive segment-level match would give.
 */
class ShadowLineTimestampMatcher
{
    /**
     * @param  list<array{text: string, start: float, end: float}>  $segments  Whisper segments, in order.
     * @return array{start: float, end: float}|null null if the line couldn't be located at all.
     */
    public function match(array $segments, string $line): ?array
    {
        [$full, $map] = $this->buildIndex($segments);

        return $this->matchWithin($full, $map, $segments, $line, 0)[0];
    }

    /**
     * Aligns a full, IN-ORDER transcript (every line of a real
     * conversation, not just a curated shadow_lines pool) against the
     * same segments. A short, generic line ("OK.", "Right.", "Wow.")
     * can appear more than once in a real transcript — an independent
     * per-line match() call has no way to tell those occurrences apart
     * and can lock onto an earlier, unrelated one (confirmed against
     * the real M01 transcript: a later "OK." matched an "OK, let's get
     * started." 100+ seconds earlier). Threading one cursor through in
     * transcript order — each line searches only from where the
     * previous line's match ended — keeps every line pinned to the
     * conversation's own real, forward-moving timeline.
     *
     * @param  list<array{text: string, start: float, end: float}>  $segments
     * @param  list<string>  $lines  In the exact order they're really spoken.
     * @return list<array{start: float, end: float}|null>
     */
    public function matchSequence(array $segments, array $lines): array
    {
        [$full, $map] = $this->buildIndex($segments);
        $cursor = 0;
        $results = [];

        foreach ($lines as $line) {
            [$result, $matchEnd] = $this->matchWithin($full, $map, $segments, $line, $cursor);
            $results[] = $result;

            if ($matchEnd !== null) {
                $cursor = $matchEnd;
            }
        }

        return $results;
    }

    /**
     * @return array{0: array{start: float, end: float}|null, 1: int|null} the timing, and the char
     *                                                                     offset (in the shared $full index) one past the match's own end — the cursor a caller
     *                                                                     doing sequential alignment resumes the NEXT search from.
     */
    private function matchWithin(string $full, array $map, array $segments, string $line, int $searchFrom): array
    {
        $target = self::normalize($line);

        if ($target === '') {
            return [null, null];
        }

        $searchFrom = min($searchFrom, strlen($full));
        $haystack = substr($full, $searchFrom);

        $pos = strpos($haystack, $target);
        $matchLength = strlen($target);

        // Whisper occasionally drops or alters a word right at a line's
        // edge (a contraction transcribed differently, a filler word
        // missed) — the fuzzy fallback keeps the match anchored to the
        // line's own real content instead of giving up on an otherwise-
        // good match.
        if ($pos === false) {
            [$pos, $matchLength] = $this->fuzzyFind($haystack, $target);
        }

        if ($pos === false || $matchLength === 0) {
            return [null, null];
        }

        $pos += $searchFrom;
        $matchEnd = $pos + $matchLength;

        $startInfo = $map[$pos] ?? null;
        $endInfo = $map[$matchEnd - 1] ?? null;

        if ($startInfo === null || $endInfo === null) {
            return [null, $matchEnd];
        }

        $start = $this->positionWithinSegment($segments[$startInfo[0]], $startInfo[1], $startInfo[2]);
        $end = $this->positionWithinSegment($segments[$endInfo[0]], $endInfo[1] + 1, $endInfo[2]);

        // A little breathing room so playback doesn't clip the line's
        // first or last sound.
        $result = [
            'start' => round(max(0.0, $start - 0.15), 2),
            'end' => round($end + 0.15, 2),
        ];

        return [$result, $matchEnd];
    }

    /**
     * @return array{0: string, 1: array<int, array{0: int, 1: int, 2: int}>}
     */
    private function buildIndex(array $segments): array
    {
        $full = '';
        $map = [];

        foreach ($segments as $segmentIndex => $segment) {
            $normalized = self::normalize($segment['text']);

            for ($offset = 0; $offset < strlen($normalized); $offset++) {
                $map[strlen($full) + $offset] = [$segmentIndex, $offset, strlen($normalized)];
            }

            // A shadow line often spans several consecutive segments —
            // Whisper frequently splits one spoken sentence into 2-3
            // short segments at its own internal pauses (confirmed
            // against real M01 audio) — so segments join with a plain
            // space, the same word boundary normalize() already uses,
            // rather than something that would block a genuine
            // multi-segment match from ever being found.
            $full .= $normalized.' ';
        }

        return [$full, $map];
    }

    /**
     * An exact substring search fails as soon as Whisper mis-hears a
     * single word anywhere in the line (confirmed against real M01
     * audio: "you've got" came back as "you got" — the contraction's
     * second half just isn't there). Slides a window of roughly the
     * target's own length across the transcript and keeps whichever
     * position has the smallest Levenshtein distance, accepting it only
     * below a real tolerance so a genuinely absent line still returns
     * no match rather than a wrong, confident-looking guess.
     *
     * @return array{0: int|false, 1: int}
     */
    private function fuzzyFind(string $full, string $target): array
    {
        $targetLength = strlen($target);
        $tolerance = max(3, (int) round($targetLength * 0.3));

        $bestPos = false;
        $bestLength = 0;
        $bestDistance = $tolerance + 1;

        for ($lengthDelta = -4; $lengthDelta <= 4; $lengthDelta++) {
            $windowLength = $targetLength + $lengthDelta;

            if ($windowLength < 4) {
                continue;
            }

            for ($pos = 0; $pos <= strlen($full) - $windowLength; $pos++) {
                $distance = levenshtein(substr($full, $pos, $windowLength), $target);

                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestPos = $pos;
                    $bestLength = $windowLength;
                }
            }
        }

        return $bestDistance <= $tolerance ? [$bestPos, $bestLength] : [false, 0];
    }

    private function positionWithinSegment(array $segment, int $charOffset, int $segmentLength): float
    {
        $ratio = $segmentLength > 0 ? $charOffset / $segmentLength : 0.0;

        return $segment['start'] + ($segment['end'] - $segment['start']) * $ratio;
    }

    private static function normalize(string $text): string
    {
        $text = str_replace('**', '', $text);
        $text = strtolower($text);
        $text = preg_replace("/[^a-z0-9' ]+/", ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }
}
