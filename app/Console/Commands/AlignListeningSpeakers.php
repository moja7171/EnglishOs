<?php

namespace App\Console\Commands;

use App\Models\Mission;
use App\Services\SpeakerTurnAligner;
use Illuminate\Console\Command;

/**
 * Adds `listening_turns` — the same real Whisper segments the synced
 * text panel already shows, split at every change of speaker and tagged
 * with who is talking — to document/{code}/shadow_timestamps.json. A
 * ONE-TIME, offline step like missions:cache-shadow-timestamps, and
 * deliberately run AFTER it: it reads that file's cached
 * `listening_segments` instead of calling Whisper again, so it needs no
 * network. Run it for every new mission whose Listening transcript has
 * speaker labels, then re-seed:
 *
 *   php artisan missions:align-listening-speakers [code]
 *   php artisan db:seed --class=MissionSeeder
 *
 * A mission with no cached segments, or a transcript without speakers,
 * is skipped (the player then keeps showing the plain segments).
 */
class AlignListeningSpeakers extends Command
{
    /**
     * Below this share of matched words, the transcript and the audio
     * really differ (an extra intro, a cut passage) and the result needs
     * a human look before it's trusted.
     */
    private const LOW_MATCH_RATE = 0.8;

    protected $signature = 'missions:align-listening-speakers {code?}';

    protected $description = 'Cache a speaker-labelled version of every mission\'s Listening segments (from the cached Whisper segments + the speaker-labelled transcript)';

    public function handle(SpeakerTurnAligner $aligner): int
    {
        $code = $this->argument('code');
        $missions = $code ? Mission::where('code', $code)->get() : Mission::orderBy('code')->get();

        if ($missions->isEmpty()) {
            $this->error($code ? "No mission found with code {$code}." : 'No missions found — seed missions first.');

            return self::FAILURE;
        }

        $anyLowMatch = false;

        foreach ($missions as $mission) {
            $anyLowMatch = $this->processMission($mission, $aligner) || $anyLowMatch;
        }

        return $anyLowMatch ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return bool true if the match was low enough to need a manual look
     */
    private function processMission(Mission $mission, SpeakerTurnAligner $aligner): bool
    {
        $path = base_path("document/{$mission->code}/shadow_timestamps.json");
        $cache = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $segments = $cache['listening_segments'] ?? [];
        $transcript = $mission->stepContent('listening')['transcript'] ?? [];

        if ($segments === []) {
            $this->warn("{$mission->code}: no cached listening_segments — run missions:cache-shadow-timestamps first. Skipping.");

            return false;
        }

        if (! collect($transcript)->every(fn ($line) => filled($line['speaker'] ?? null)) || $transcript === []) {
            $this->warn("{$mission->code}: Listening transcript has no speaker labels — skipping.");

            return false;
        }

        $result = $aligner->align($segments, $transcript);
        $cache['listening_turns'] = $result['turns'];

        file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $speakers = collect($result['turns'])->pluck('speaker')->countBy()->map(fn ($count, $name) => "{$name}: {$count}")->implode(', ');
        $low = min($result['spokenMatchRate'], $result['transcriptMatchRate']) < self::LOW_MATCH_RATE;

        $this->{$low ? 'warn' : 'info'}(sprintf(
            '%s: %d chunks (%s). Matched %d%% of the audio\'s words, %d%% of the transcript\'s%s',
            $mission->code,
            count($result['turns']),
            $speakers,
            $result['spokenMatchRate'] * 100,
            $result['transcriptMatchRate'] * 100,
            $low ? ' — LOW, review before relying on it.' : '.',
        ));

        return $low;
    }
}
