<?php

namespace App\Console\Commands;

use App\Models\Mission;
use App\Services\GroqClient;
use App\Services\ShadowLineTimestampMatcher;
use App\Services\WebVttParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
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
 * Every line is matched against WORD-level Whisper timing, so its pause
 * lands exactly where its last word ends. Segment or caption-cue timing
 * isn't precise enough for that: a single segment or cue often holds
 * several sentences, and a line inside it could only be placed by
 * guessing from its character offset — which is what made pauses land
 * on the sentence before or after. daily_listen_N lines are matched
 * against Day 1's Listening audio; video_shadowing lines against the
 * video's own audio track (extracted with ffmpeg), falling back to the
 * captions' cue timing when ffmpeg isn't installed.
 *
 * Whisper is called only for what isn't cached yet: the word timelines
 * (`listening_words`, `video_shadowing_words`) and Day 1's synced-text
 * segments are kept in the cache file and reused, so re-running after a
 * shadow_lines edit needs no network and never shifts the segments that
 * missions:align-listening-speakers built `listening_turns` from. Keys
 * this command doesn't own are left as they are. Pass --fresh to
 * transcribe again anyway (needs real network access — set
 * AI_PROXY_URL/AI_PROXY_SECRET first if this environment can't reach
 * api.groq.com directly).
 */
class CacheShadowTimestamps extends Command
{
    protected $signature = 'missions:cache-shadow-timestamps {code?} {--fresh : Transcribe again instead of reusing cached Whisper output}';

    protected $description = 'Cache real start/end timestamps for every mission\'s shadow_lines (word-level Whisper timing for daily_listen_N and video_shadowing)';

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
        $path = base_path("document/{$mission->code}/shadow_timestamps.json");
        $cache = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        $unmatched = false;
        $changed = false;

        foreach ($mission->phases ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                $key = is_array($step) ? ($step['key'] ?? null) : null;
                $lines = is_array($step) ? ($step['shadow_lines'] ?? []) : [];

                if (! $key || ! count($lines)) {
                    continue;
                }

                if ($key === 'video_shadowing') {
                    // Same "reuse the real timed text directly, no
                    // alignment needed" approach as listening_segments —
                    // drives Video Shadowing's own synced text panel.
                    $cues = $this->videoShadowingCues($mission, $step);

                    if ($cues !== null) {
                        $cache['video_shadowing_segments'] = $cues;
                    }

                    $words = $this->videoWords($mission, $step, $groq, $cache);
                    $timeline = $words !== null ? self::trimSilenceFromWords($words) : $cues;
                    $source = isset($cache['video_shadowing_words']) ? 'Whisper words' : 'captions';
                } elseif (str_starts_with($key, 'daily_listen_')) {
                    $words = $this->listeningWords($mission, $groq, $cache);
                    $timeline = $words !== null ? self::trimSilenceFromWords($words) : null;
                    $source = 'Whisper words';
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

                $cache[$key] = $timestamps;
                $changed = true;
            }
        }

        if (! $changed) {
            $this->warn("{$mission->code}: no shadow_lines found anywhere — skipping.");

            return false;
        }

        file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("{$mission->code}: wrote {$path}".($unmatched ? ' (with unmatched lines — fix before relying on it)' : ''));

        return $unmatched;
    }

    /**
     * Whisper hands the silence before a word to that word: after a pause,
     * the next word "starts" where the previous one ended (confirmed on
     * real M01 video audio: "The" ran 40.76s-44.84s, a 4-second word). A
     * line starting on such a word would be replayed from inside the
     * sentence before it, so any word longer than it could plausibly take
     * to say has its start moved up to that plausible length before its
     * end. Its end is kept — that's where the speaking really stops.
     *
     * @param  list<array{text: string, start: float, end: float}>  $words
     * @return list<array{text: string, start: float, end: float}>
     */
    public static function trimSilenceFromWords(array $words): array
    {
        return array_map(function (array $word): array {
            $plausibleSeconds = 0.25 + 0.08 * mb_strlen($word['text']);

            if ($plausibleSeconds < $word['end'] - $word['start']) {
                $word['start'] = round($word['end'] - $plausibleSeconds, 2);
            }

            return $word;
        }, $words);
    }

    /**
     * Word-level timing of Day 1's Listening audio, plus its segments for
     * the synced text panel when they aren't cached yet. Day 1 Listening's
     * synced-text display reads Whisper's own segments directly (real
     * text, real timing, zero ambiguity) — NOT an attempt to align them to
     * the hand-authored, speaker-labelled transcript; see
     * missions:align-listening-speakers for the speaker-tagged version.
     *
     * @param  array<string, mixed>  $cache
     * @return list<array{text: string, start: float, end: float}>|null
     */
    private function listeningWords(Mission $mission, GroqClient $groq, array &$cache): ?array
    {
        if (! $this->option('fresh') && isset($cache['listening_words'], $cache['listening_segments'])) {
            return $cache['listening_words'];
        }

        $audioUrl = $mission->stepContent('listening')['audio_url'] ?? null;
        $localPath = $audioUrl ? $this->resolveLocalPath($mission, $audioUrl) : null;

        if (! $localPath) {
            $this->warn("{$mission->code}: Listening audio file not found — skipping its daily_listen_N lines.");

            return null;
        }

        if ($this->option('fresh') || ! isset($cache['listening_segments'])) {
            $this->info("{$mission->code}: transcribing ".basename($localPath).' into segments via Whisper...');
            $cache['listening_segments'] = $groq->transcribeSegmentsWithTimestamps($localPath);
        }

        $this->info("{$mission->code}: transcribing ".basename($localPath).' into words via Whisper...');
        $cache['listening_words'] = $groq->transcribeWordsWithTimestamps($localPath);
        $this->info("{$mission->code}: got ".count($cache['listening_words']).' words.');

        return $cache['listening_words'];
    }

    /**
     * Word-level timing of the shadowing video's own audio track, or null
     * (the caller then falls back to the captions' cue timing) when the
     * video file or ffmpeg isn't available.
     *
     * @param  array<string, mixed>  $cache
     * @return list<array{text: string, start: float, end: float}>|null
     */
    private function videoWords(Mission $mission, array $step, GroqClient $groq, array &$cache): ?array
    {
        if (! $this->option('fresh') && isset($cache['video_shadowing_words'])) {
            return $cache['video_shadowing_words'];
        }

        $videoPath = ($step['video_url'] ?? null) ? $this->resolveLocalPath($mission, $step['video_url']) : null;

        if (! $videoPath) {
            $this->warn("{$mission->code}: video_shadowing video file not found — using caption cue timing.");

            return null;
        }

        // A small mono track keeps the upload well under Whisper's file
        // size limit; the video itself is far too big to send.
        $audioPath = storage_path('app/private/'.Str::uuid().'.mp3');
        $result = Process::timeout(300)->run(['ffmpeg', '-y', '-loglevel', 'error', '-i', $videoPath, '-vn', '-ac', '1', '-ar', '16000', '-b:a', '32k', $audioPath]);

        if ($result->failed() || ! is_file($audioPath)) {
            $this->warn("{$mission->code}: couldn't extract the video's audio with ffmpeg — using caption cue timing.");

            return null;
        }

        try {
            $this->info("{$mission->code}: transcribing the video's audio into words via Whisper...");
            $cache['video_shadowing_words'] = $groq->transcribeWordsWithTimestamps($audioPath);
        } finally {
            @unlink($audioPath);
        }

        $this->info("{$mission->code}: got ".count($cache['video_shadowing_words']).' words.');

        return $cache['video_shadowing_words'];
    }

    /**
     * @return list<array{text: string, start: float, end: float}>|null
     */
    private function videoShadowingCues(Mission $mission, array $step): ?array
    {
        $captionsUrl = $step['captions_url'] ?? null;

        if (! $captionsUrl) {
            $this->warn("{$mission->code}: video_shadowing has no captions_url — skipping its text panel.");

            return null;
        }

        $localPath = $this->resolveLocalPath($mission, $captionsUrl);

        if (! $localPath) {
            $this->warn("{$mission->code}: video_shadowing captions file not found — skipping its text panel.");

            return null;
        }

        $cues = WebVttParser::parse(file_get_contents($localPath));
        $this->info("{$mission->code}: parsed ".count($cues).' caption cues for video_shadowing.');

        return $cues;
    }

    /**
     * The local file behind a mission asset's URL: the published copy in
     * storage, or — for audio/video served from the download host — the
     * original in document/{code}/, which is where this offline command
     * runs.
     */
    private function resolveLocalPath(Mission $mission, string $publicUrl): ?string
    {
        $storagePath = Storage::disk('public')->path(Str::after($publicUrl, '/storage/'));

        if (is_file($storagePath)) {
            return $storagePath;
        }

        $documentPath = base_path("document/{$mission->code}/".rawurldecode(basename($publicUrl)));

        return is_file($documentPath) ? $documentPath : null;
    }
}
