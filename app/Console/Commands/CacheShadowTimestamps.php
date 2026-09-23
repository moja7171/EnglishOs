<?php

namespace App\Console\Commands;

use App\Models\Mission;
use App\Services\GroqClient;
use App\Services\ShadowLineTimestampMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates real, timed pause points for every seeded shadow_lines entry
 * that reuses Day 1's Listening audio (daily_listen_2/3/4 — see
 * DailyListenStep) — a ONE-TIME, offline step, same "generate once,
 * never live on a page view" principle as PexelsClient's warmed image
 * cache. Run this after editing a mission's shadow_lines, then re-seed:
 *
 *   php artisan missions:cache-shadow-timestamps [code]
 *   php artisan db:seed --class=MissionSeeder
 *
 * Needs real Groq (Whisper) access — set AI_PROXY_URL/AI_PROXY_SECRET
 * first if this environment can't reach api.groq.com directly.
 */
class CacheShadowTimestamps extends Command
{
    protected $signature = 'missions:cache-shadow-timestamps {code?}';

    protected $description = 'Transcribe each mission\'s Listening audio via Whisper and cache real start/end timestamps for its shadow_lines';

    public function handle(GroqClient $groq, ShadowLineTimestampMatcher $matcher): int
    {
        $code = $this->argument('code');
        $missions = $code ? Mission::where('code', $code)->get() : Mission::orderBy('code')->get();

        if ($missions->isEmpty()) {
            $this->error($code ? "No mission found with code {$code}." : 'No missions found — seed missions first.');

            return self::FAILURE;
        }

        $anyUnmatched = false;

        foreach ($missions as $mission) {
            $anyUnmatched = $this->processMission($mission, $groq, $matcher) || $anyUnmatched;
        }

        return $anyUnmatched ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return bool true if any line in this mission couldn't be matched
     */
    private function processMission(Mission $mission, GroqClient $groq, ShadowLineTimestampMatcher $matcher): bool
    {
        $audioUrl = $mission->stepContent('listening')['audio_url'] ?? null;

        if (! $audioUrl) {
            $this->warn("{$mission->code}: no Listening audio_url — skipping.");

            return false;
        }

        $localPath = Storage::disk('public')->path(Str::after($audioUrl, '/storage/'));

        if (! is_file($localPath)) {
            $this->warn("{$mission->code}: audio file not found at {$localPath} — skipping.");

            return false;
        }

        $this->info("{$mission->code}: transcribing ".basename($localPath).' via Whisper...');
        $segments = $groq->transcribeSegmentsWithTimestamps($localPath);
        $this->info("{$mission->code}: got ".count($segments).' segments.');

        $result = [];
        $unmatched = false;

        foreach ($mission->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                $key = is_array($step) ? ($step['key'] ?? null) : null;
                $lines = is_array($step) ? ($step['shadow_lines'] ?? []) : [];

                // Only daily_listen_N reuses THIS audio (see
                // DailyListenStep::listeningContent()) — video_shadowing
                // has its own separate video/audio entirely and gets its
                // timestamps from its own real .vtt captions instead.
                if (! $key || ! str_starts_with($key, 'daily_listen_') || ! count($lines)) {
                    continue;
                }

                $timestamps = [];

                foreach ($lines as $index => $line) {
                    $match = $matcher->match($segments, $line);
                    $timestamps[] = $match;

                    if ($match === null) {
                        $unmatched = true;
                        $this->error("  {$key}[{$index}]: NO MATCH for \"{$line}\" — needs manual review.");
                    } else {
                        $this->line("  {$key}[{$index}]: {$match['start']}s - {$match['end']}s — \"{$line}\"");
                    }
                }

                $result[$key] = $timestamps;
            }
        }

        $path = base_path("document/{$mission->code}/shadow_timestamps.json");
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("{$mission->code}: wrote {$path}".($unmatched ? ' (with unmatched lines — fix before relying on it)' : ''));

        return $unmatched;
    }
}
