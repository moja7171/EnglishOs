<?php

namespace App\Services;

/**
 * Puts a speaker on every stretch of a conversation's real, timed audio
 * (Whisper segments, which know WHEN but not WHO) using the hand-
 * authored, speaker-labelled transcript (which knows WHO but not WHEN).
 * The result drives the chat-style synced text panel of <x-audio-player>
 * — see missions:align-listening-speakers, the only caller, which runs
 * this once per mission and caches the output as `listening_turns` in
 * document/{code}/shadow_timestamps.json, the same "generate once,
 * never live" pattern as the rest of that file.
 *
 * An earlier attempt matched each whole transcript turn against the
 * segments and never settled on the long, multi-sentence turns. This
 * aligns WORD by word instead (a longest-common-subsequence over the
 * two word sequences, both of which run in the same order), so a turn
 * boundary in the middle of a Whisper segment is found exactly where
 * the words say it is, and a word Whisper heard differently just stays
 * unmatched and takes its speaker from its matched neighbours.
 *
 * Word times inside a segment are interpolated by character offset —
 * the same constant-pace assumption as ShadowLineTimestampMatcher.
 */
class SpeakerTurnAligner
{
    /**
     * @param  list<array{text: string, start: float, end: float}>  $segments  Whisper segments, in order.
     * @param  list<array{speaker: string, text: string}>  $transcript  Every line, in the order really spoken.
     * @return array{
     *     turns: list<array{speaker: string, text: string, start: float, end: float}>,
     *     spokenMatchRate: float,
     *     transcriptMatchRate: float
     * } turns are one chunk per (Whisper segment, speaker) run — consecutive chunks of one speaker are one
     *   turn to the reader. The rates are the share of Whisper's words / the transcript's words that found
     *   a counterpart: a low rate means the transcript and the audio really differ and needs a manual look.
     */
    public function align(array $segments, array $transcript): array
    {
        $spoken = $this->spokenWords($segments);
        $written = $this->writtenWords($transcript);

        $pairs = $this->longestCommonSubsequence(
            array_column($spoken, 'norm'),
            array_column($written, 'norm'),
        );

        $speakers = $this->speakerPerSpokenWord($spoken, $written, $pairs);

        return [
            'turns' => $this->chunks($spoken, $speakers),
            'spokenMatchRate' => $spoken === [] ? 0.0 : round(count($pairs) / count($spoken), 3),
            'transcriptMatchRate' => $written === [] ? 0.0 : round(count($pairs) / count($written), 3),
        ];
    }

    /**
     * @param  list<array{text: string, start: float, end: float}>  $segments
     * @return list<array{raw: string, norm: string, segment: int, start: float, end: float}>
     */
    private function spokenWords(array $segments): array
    {
        $words = [];

        foreach ($segments as $segmentIndex => $segment) {
            $tokens = preg_split('/\s+/', trim($segment['text']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $total = max(1, strlen(implode(' ', $tokens)));
            $duration = $segment['end'] - $segment['start'];
            $offset = 0;

            foreach ($tokens as $token) {
                $norm = self::normalize($token);
                $start = $segment['start'] + $duration * $offset / $total;
                $offset += strlen($token) + 1;
                $end = $segment['start'] + $duration * min($offset - 1, $total) / $total;

                if ($norm === '') {
                    continue;
                }

                $words[] = [
                    'raw' => $token,
                    'norm' => $norm,
                    'segment' => $segmentIndex,
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        return $words;
    }

    /**
     * @param  list<array{speaker: string, text: string}>  $transcript
     * @return list<array{norm: string, speaker: string}>
     */
    private function writtenWords(array $transcript): array
    {
        $words = [];

        foreach ($transcript as $line) {
            foreach (preg_split('/\s+/', trim($line['text']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                $norm = self::normalize($token);

                if ($norm !== '') {
                    $words[] = ['norm' => $norm, 'speaker' => $line['speaker']];
                }
            }
        }

        return $words;
    }

    /**
     * When two alignments are equally long (a phrase like "how are you"
     * or "thank you" said by both speakers), the traceback skips a word
     * that isn't needed rather than matching it greedily, and between
     * two spare words steps back along whichever text is further ahead —
     * so a match stays near the diagonal (the same point in both texts)
     * instead of latching onto a later repeat of the phrase.
     *
     * @param  list<string>  $spoken
     * @param  list<string>  $written
     * @return list<array{0: int, 1: int}> matched (spoken index, written index) pairs, in order.
     */
    private function longestCommonSubsequence(array $spoken, array $written): array
    {
        $rows = count($spoken);
        $columns = count($written);
        $width = $columns + 1;
        $table = array_fill(0, ($rows + 1) * $width, 0);

        for ($i = 1; $i <= $rows; $i++) {
            for ($j = 1; $j <= $columns; $j++) {
                $table[$i * $width + $j] = self::sameWord($spoken[$i - 1], $written[$j - 1])
                    ? $table[($i - 1) * $width + $j - 1] + 1
                    : max($table[($i - 1) * $width + $j], $table[$i * $width + $j - 1]);
            }
        }

        $pairs = [];
        $i = $rows;
        $j = $columns;

        while ($i > 0 && $j > 0) {
            $length = $table[$i * $width + $j];
            $spokenIsSpare = $table[($i - 1) * $width + $j] === $length;
            $writtenIsSpare = $table[$i * $width + $j - 1] === $length;

            if ($spokenIsSpare && $writtenIsSpare) {
                if ($i * $columns >= $j * $rows) {
                    $i--;
                } else {
                    $j--;
                }
            } elseif ($spokenIsSpare) {
                $i--;
            } elseif ($writtenIsSpare) {
                $j--;
            } else {
                $pairs[] = [$i - 1, $j - 1];
                $i--;
                $j--;
            }
        }

        return array_reverse($pairs);
    }

    /**
     * Every spoken word gets the speaker of the transcript word it
     * matched. An unmatched word between two matches is placed
     * proportionally between them (so a turn boundary inside a stretch
     * Whisper heard differently still lands about right), and one before
     * the first or after the last match takes that match's speaker.
     *
     * @param  list<array{raw: string, norm: string, segment: int, start: float, end: float}>  $spoken
     * @param  list<array{norm: string, speaker: string}>  $written
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return list<string>
     */
    private function speakerPerSpokenWord(array $spoken, array $written, array $pairs): array
    {
        if ($written === [] || $pairs === []) {
            return array_fill(0, count($spoken), $written[0]['speaker'] ?? '');
        }

        $speakers = [];

        foreach ($pairs as [$spokenIndex, $writtenIndex]) {
            $speakers[$spokenIndex] = $written[$writtenIndex]['speaker'];
        }

        $firstSpoken = $pairs[0][0];
        $lastSpoken = $pairs[array_key_last($pairs)][0];

        for ($i = 0; $i < $firstSpoken; $i++) {
            $speakers[$i] = $speakers[$firstSpoken];
        }

        for ($i = $lastSpoken + 1; $i < count($spoken); $i++) {
            $speakers[$i] = $speakers[$lastSpoken];
        }

        for ($a = 0; $a < count($pairs) - 1; $a++) {
            [$spokenFrom, $writtenFrom] = $pairs[$a];
            [$spokenTo, $writtenTo] = $pairs[$a + 1];

            for ($i = $spokenFrom + 1; $i < $spokenTo; $i++) {
                $fraction = ($i - $spokenFrom) / ($spokenTo - $spokenFrom);
                $position = (int) round($writtenFrom + ($writtenTo - $writtenFrom) * $fraction);
                $speakers[$i] = $written[max(0, min(count($written) - 1, $position))]['speaker'];
            }
        }

        ksort($speakers);

        return $this->smoothUnmatchedFlips($spoken, array_values($speakers), array_flip(array_column($pairs, 0)));
    }

    /**
     * An unmatched word whose guessed speaker differs from both
     * neighbours inside one segment is alignment noise, not a real
     * one-word turn. A MATCHED word is left alone — a genuine "Good."
     * from the other person can sit inside a segment Whisper merged.
     *
     * @param  list<array{segment: int}>  $spoken
     * @param  list<string>  $speakers
     * @param  array<int, int>  $matched  spoken indexes that found a transcript counterpart, as keys.
     * @return list<string>
     */
    private function smoothUnmatchedFlips(array $spoken, array $speakers, array $matched): array
    {
        for ($i = 1; $i < count($speakers) - 1; $i++) {
            $sameSegment = $spoken[$i - 1]['segment'] === $spoken[$i]['segment']
                && $spoken[$i]['segment'] === $spoken[$i + 1]['segment'];

            if (! isset($matched[$i]) && $sameSegment && $speakers[$i - 1] === $speakers[$i + 1] && $speakers[$i] !== $speakers[$i - 1]) {
                $speakers[$i] = $speakers[$i - 1];
            }
        }

        return $speakers;
    }

    /**
     * @param  list<array{raw: string, norm: string, segment: int, start: float, end: float}>  $spoken
     * @param  list<string>  $speakers
     * @return list<array{speaker: string, text: string, start: float, end: float}>
     */
    private function chunks(array $spoken, array $speakers): array
    {
        $chunks = [];

        foreach ($spoken as $index => $word) {
            $last = array_key_last($chunks);

            if ($last !== null && $chunks[$last]['segment'] === $word['segment'] && $chunks[$last]['speaker'] === $speakers[$index]) {
                $chunks[$last]['text'] .= ' '.$word['raw'];
                $chunks[$last]['end'] = $word['end'];

                continue;
            }

            $chunks[] = [
                'segment' => $word['segment'],
                'speaker' => $speakers[$index],
                'text' => $word['raw'],
                'start' => $word['start'],
                'end' => $word['end'],
            ];
        }

        return array_map(fn (array $chunk) => [
            'speaker' => $chunk['speaker'],
            'text' => $chunk['text'],
            'start' => round($chunk['start'], 2),
            'end' => round($chunk['end'], 2),
        ], $chunks);
    }

    /**
     * Same word, allowing one slip in a longer word (Whisper's spelling
     * of a name or a plural often differs by a letter).
     */
    private static function sameWord(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return strlen($a) >= 5 && strlen($b) >= 5 && $a[0] === $b[0] && levenshtein($a, $b) <= 1;
    }

    private static function normalize(string $token): string
    {
        $token = strtolower(str_replace('**', '', $token));
        $token = str_replace(['’', '‘'], "'", $token);

        return trim((string) preg_replace("/[^a-z0-9']+/", '', $token), "'");
    }
}
