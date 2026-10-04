<?php

namespace App\Console\Commands;

use App\Services\SpeakerTurnAligner;
use Illuminate\Console\Command;

/**
 * Builds the synced-text file of every in-app listening pick: for each
 * entry of document/{code}/picks/index.json, joins its Whisper segments
 * ({slug}.segments.json) with its speaker-labelled transcript
 * ({slug}.transcript.json, when there is one) into {slug}.turns.json —
 * the one file the /listening page's player reads. A pick without a
 * transcript keeps its plain segments (no speaker names), like any
 * mission listening without speaker labels.
 *
 * A ONE-TIME, offline step like missions:align-listening-speakers; the
 * downloading, transcribing and PDF parsing before it are
 * scripts/listening-picks/*.py.
 *
 *   php artisan listening:build-picks [code]
 */
class BuildListeningPicks extends Command
{
    /**
     * Below this share of matched words, the transcript and the audio
     * really differ and the result needs a human look.
     */
    private const LOW_MATCH_RATE = 0.8;

    protected $signature = 'listening:build-picks {code?}';

    protected $description = 'Build the speaker-labelled synced text of every in-app listening pick (from its Whisper segments and its transcript)';

    public function handle(SpeakerTurnAligner $aligner): int
    {
        $indexes = glob(base_path('document/'.($this->argument('code') ?? 'M*').'/picks/index.json')) ?: [];

        if ($indexes === []) {
            $this->error('No document/{code}/picks/index.json found.');

            return self::FAILURE;
        }

        $anyLowMatch = false;

        foreach ($indexes as $indexPath) {
            $code = basename(dirname($indexPath, 2));

            foreach (json_decode((string) file_get_contents($indexPath), true) as $dayIndex => $picks) {
                foreach ($picks as $pick) {
                    $anyLowMatch = $this->buildPick($code, $dayIndex + 1, dirname($indexPath), $pick['slug'], $aligner) || $anyLowMatch;
                }
            }
        }

        return $anyLowMatch ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return bool true if the match was low enough to need a manual look
     */
    private function buildPick(string $code, int $day, string $folder, string $slug, SpeakerTurnAligner $aligner): bool
    {
        $label = "{$code} day {$day} {$slug}";
        $segmentsPath = "{$folder}/{$slug}.segments.json";

        if (! is_file($segmentsPath)) {
            $this->warn("{$label}: no {$slug}.segments.json yet — skipped.");

            return false;
        }

        $data = json_decode((string) file_get_contents($segmentsPath), true);
        $transcriptPath = "{$folder}/{$slug}.transcript.json";
        $transcript = is_file($transcriptPath) ? json_decode((string) file_get_contents($transcriptPath), true) : [];
        $low = false;

        if ($transcript === []) {
            $segments = $data['segments'];
            $note = 'no transcript, plain segments';
        } else {
            $result = $aligner->align($data['segments'], $transcript);
            $segments = $result['turns'];
            $low = min($result['spokenMatchRate'], $result['transcriptMatchRate']) < self::LOW_MATCH_RATE;
            $note = sprintf(
                '%d%% of the audio matched, %d%% of the transcript%s',
                $result['spokenMatchRate'] * 100,
                $result['transcriptMatchRate'] * 100,
                $low ? ' — LOW, review' : '',
            );
        }

        file_put_contents(
            "{$folder}/{$slug}.turns.json",
            json_encode(['duration' => $data['duration'], 'segments' => $segments], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $this->{$low ? 'warn' : 'line'}("{$label}: ".count($segments)." chunks ({$note})");

        return $low;
    }
}
