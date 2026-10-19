<?php

namespace App\Services;

/**
 * Where the missions' audio and video live once they are served from the
 * download host (config/media.php) instead of this app's own disk: one
 * folder per mission, `{base}/m01/Some File.mp4`, with that mission's
 * listening picks in `{base}/m01/picks/{slug}.mp3`. Every method returns
 * null while no host is configured, so the caller keeps serving the file
 * from here — local development, tests.
 *
 * Only audio and video go there. Captions (.vtt), the picks' synced text
 * and the learners' own recordings are small and stay on this app, which
 * also keeps the video's captions same-origin.
 */
class MediaHost
{
    /**
     * @var list<string>
     */
    private const HOSTED_EXTENSIONS = ['mp3', 'mp4'];

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== null;
    }

    /**
     * The URL of one of a mission's own audio/video files, or null when it
     * stays on this app (no host configured, or not an audio/video file).
     */
    public function missionFileUrl(string $missionCode, string $filename): ?string
    {
        if (! in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::HOSTED_EXTENSIONS, true)) {
            return null;
        }

        return $this->url(strtolower($missionCode), rawurlencode($filename));
    }

    public function pickAudioUrl(string $missionCode, string $slug): ?string
    {
        return $this->url(strtolower($missionCode), 'picks', rawurlencode($slug).'.mp3');
    }

    private function url(string ...$segments): ?string
    {
        $baseUrl = $this->baseUrl();

        return $baseUrl === null ? null : $baseUrl.'/'.implode('/', $segments);
    }

    private function baseUrl(): ?string
    {
        $baseUrl = config('media.base_url');

        return is_string($baseUrl) && $baseUrl !== '' ? rtrim($baseUrl, '/') : null;
    }
}
