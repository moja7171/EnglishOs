<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Thin wrapper around the Pexels photo + video search APIs. Photos power
 * Vocabulary Builder's picture flashcards (dual-coding: a concrete-noun
 * word paired with an image aids recall more than the word alone).
 * Videos power ambient/decorative background clips (see
 * <x-ambient-video>) — deliberately NOT a listening-comprehension source:
 * Pexels footage has no usable spoken dialogue to test against, it is
 * mood-setting only. Deliberately fails soft everywhere (missing key,
 * network error, no results all return null) — this is a nice-to-have,
 * never something a step should block or error out on. See EOS-009 §8.
 */
class PexelsClient
{
    private readonly string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? (string) config('services.pexels.key');
    }

    /**
     * The image for one vocabulary word, fetched from Pexels once and then
     * cached forever on local storage — the same word means the same
     * image for every learner, so there's no reason to ever call the API
     * twice for it. Returns null (never throws) if there's no API key, no
     * search results, or the request fails for any reason.
     */
    public function imageUrlFor(string $word, string $query): ?string
    {
        return $this->fetchAndCache(
            'vocabulary-images/'.Str::slug($word).'.jpg',
            fn () => $this->searchPhotoUrl($query),
        );
    }

    /**
     * A short, silent background video clip for one topic, same fetch-once
     * -cache-forever shape as imageUrlFor(). $identifier scopes the cached
     * file (e.g. a mission code), $query is what's actually searched for.
     */
    public function videoUrlFor(string $identifier, string $query): ?string
    {
        return $this->fetchAndCache(
            'ambient-videos/'.Str::slug($identifier).'.mp4',
            fn () => $this->searchVideoUrl($query),
        );
    }

    /**
     * @param  callable(): ?string  $resolveRemoteUrl
     */
    private function fetchAndCache(string $path, callable $resolveRemoteUrl): ?string
    {
        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        $remoteUrl = $resolveRemoteUrl();

        if (! $remoteUrl) {
            return null;
        }

        try {
            $bytes = Http::get($remoteUrl)->throw()->body();
            Storage::disk('public')->put($path, $bytes);

            return Storage::disk('public')->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function searchPhotoUrl(string $query): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $this->apiKey])
                ->get('https://api.pexels.com/v1/search', [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'square',
                ])
                ->throw();
        } catch (Throwable) {
            return null;
        }

        return data_get($response->json(), 'photos.0.src.medium');
    }

    /**
     * Picks the smallest "sd" file when one exists (this is a muted,
     * looping background loop, not something worth HD bandwidth), falling
     * back to whichever file Pexels lists first.
     */
    private function searchVideoUrl(string $query): ?string
    {
        if ($this->apiKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => $this->apiKey])
                ->get('https://api.pexels.com/videos/search', [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                ])
                ->throw();
        } catch (Throwable) {
            return null;
        }

        $files = collect(data_get($response->json(), 'videos.0.video_files', []));

        return $files->firstWhere('quality', 'sd')['link'] ?? $files->first()['link'] ?? null;
    }
}
