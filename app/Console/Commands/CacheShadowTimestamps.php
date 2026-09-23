<?php

namespace App\Console\Commands;

use App\Models\Mission;
use App\Services\GroqClient;
use App\Services\ShadowLineTimestampMatcher;
use App\Services\WebVttParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates real, timed pause points for every seeded shadow_lines entry
 * — a ONE-TIME, offline step, same "generate once, never live on a page
 * view" principle as PexelsClient's warmed image cache. Run this after
 * editing a mission's shadow_lines, then re-seed:
 *
 *   php artisan missions:cache-shadow-timestamps [code]
 *   php artisan db:seed --class=MissionSeeder
 *
 * daily_listen_N (see DailyListenStep) reuses Day 1's Listening audio,
 * which has no pre-existing timing, so those lines are matched against
 * a fresh Groq Whisper transcription (needs real network access — set
 * AI_PROXY_URL/AI_PROXY_SECRET first if this environment can't reach
 * api.groq.com directly). video_shadowing already has real, timed
 * WebVTT captions (captions_url) for its own separate video, so those
 * lines are matched against the parsed .vtt instead — no Whisper call,
 * no network needed for that half.
 */
class CacheShadowTimestamps extends Command
{
    protected $signature = 'missions:cache-shadow-timestamps {code?}';

    protected $description = 'Cache real start/end timestamps for every mission\'s shadow_lines (Whisper for daily_listen_N, real captions for video_shadowing)';

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
        $result = [];
        $unmatched = false;

        $listeningSegments = null;

        foreach ($mission->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                $key = is_array($step) ? ($step['key'] ?? null) : null;
                $lines = is_array($step) ? ($step['shadow_lines'] ?? []) : [];

                if (! $key || ! count($lines)) {
                    continue;
                }

                if ($key === 'video_shadowing') {
                    $timeline = $this->videoShadowingCues($mission, $step);
                    $source = 'real captions';
                } elseif (str_starts_with($key, 'daily_listen_')) {
                    $listeningSegments ??= $this->listeningSegments($mission, $groq);
                    $timeline = $listeningSegments;
                    $source = 'Whisper';
                } else {
                    continue;
                }

                if ($timeline === null) {
                    continue;
                }

                $timestamps = [];

                foreach ($lines as $index => $line) {
                    $match = $matcher->match($timeline, $line);
                    $timestamps[] = $match;

                    if ($match === null) {
                        $unmatched = true;
                        $this->error("  {$key}[{$index}] ({$source}): NO MATCH for \"{$line}\" — needs manual review.");
                    } else {
                        $this->line("  {$key}[{$index}] ({$source}): {$match['start']}s - {$match['end']}s — \"{$line}\"");
                    }
                }

                $result[$key] = $timestamps;
            }
        }

        if ($result === []) {
            $this->warn("{$mission->code}: no shadow_lines found anywhere — skipping.");

            return false;
        }

        $path = base_path("document/{$mission->code}/shadow_timestamps.json");
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("{$mission->code}: wrote {$path}".($unmatched ? ' (with unmatched lines — fix before relying on it)' : ''));

        return $unmatched;
    }

    /**
     * @return list<array{text: string, start: float, end: float}>|null
     */
    private function listeningSegments(Mission $mission, GroqClient $groq): ?array
    {
        $audioUrl = $mission->stepContent('listening')['audio_url'] ?? null;

        if (! $audioUrl) {
            $this->warn("{$mission->code}: no Listening audio_url — skipping its daily_listen_N lines.");

            return null;
        }

        $localPath = $this->resolveLocalPath($audioUrl);

        if (! $localPath) {
            $this->warn("{$mission->code}: Listening audio file not found — skipping its daily_listen_N lines.");

            return null;
        }

        $this->info("{$mission->code}: transcribing ".basename($localPath).' via Whisper...');
        $segments = $groq->transcribeSegmentsWithTimestamps($localPath);
        $this->info("{$mission->code}: got ".count($segments).' segments.');

        return $segments;
    }

    /**
     * @return list<array{text: string, start: float, end: float}>|null
     */
    private function videoShadowingCues(Mission $mission, array $step): ?array
    {
        $captionsUrl = $step['captions_url'] ?? null;

        if (! $captionsUrl) {
            $this->warn("{$mission->code}: video_shadowing has no captions_url — skipping.");

            return null;
        }

        $localPath = $this->resolveLocalPath($captionsUrl);

        if (! $localPath) {
            $this->warn("{$mission->code}: video_shadowing captions file not found — skipping.");

            return null;
        }

        $cues = WebVttParser::parse(file_get_contents($localPath));
        $this->info("{$mission->code}: parsed ".count($cues).' caption cues for video_shadowing.');

        return $cues;
    }

    private function resolveLocalPath(string $publicUrl): ?string
    {
        $localPath = Storage::disk('public')->path(Str::after($publicUrl, '/storage/'));

        return is_file($localPath) ? $localPath : null;
    }
}
