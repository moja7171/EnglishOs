<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around MyMemory's free translation memory, used for the
 * Persian meaning of a single word. Its headline `translatedText` is often
 * the English word handed straight back (checked live: "sleep" came back as
 * "sleep"), while the real translation sits in `matches` — so this reads
 * the matches and keeps only a Persian-script one for exactly the word
 * asked about. Only used through WordLookup, which caches the result.
 */
class MyMemoryClient
{
    use UsesOutboundProxy;

    private const BASE_URL = 'https://api.mymemory.translated.net/get';

    /**
     * The best Persian translation of $word, or null when MyMemory had
     * nothing usable (including the quota-used-up warning it returns in
     * place of a translation). A connection failure or a 5xx throws.
     */
    public function translateToPersian(string $word): ?string
    {
        $url = self::BASE_URL.'?'.http_build_query(array_filter([
            'q' => $word,
            'langpair' => 'en|fa',
            'de' => config('services.mymemory.email'),
        ]));

        $body = $this->withOutboundProxy(Http::connectTimeout(3)->timeout(6), $url)
            ->get($this->outboundUrl($url))
            ->throw()
            ->json();

        if (! is_array($body) || (int) ($body['responseStatus'] ?? 0) !== 200 || ($body['quotaFinished'] ?? false)) {
            return null;
        }

        $best = collect($body['matches'] ?? [])
            ->filter(fn (array $match) => $this->isPersian((string) ($match['translation'] ?? ''))
                && $this->sameWord((string) ($match['segment'] ?? ''), $word))
            ->sortByDesc(fn (array $match) => [(float) ($match['match'] ?? 0), (int) ($match['usage-count'] ?? 0)])
            ->first();

        $translation = $best['translation'] ?? $this->headlineTranslation($body);

        return $translation === null ? null : $this->tidy($translation);
    }

    /**
     * The headline translation, trusted only when it is Persian and
     * MyMemory is reasonably sure of it.
     *
     * @param  array<string, mixed>  $body
     */
    private function headlineTranslation(array $body): ?string
    {
        $text = (string) data_get($body, 'responseData.translatedText', '');
        $confidence = (float) data_get($body, 'responseData.match', 0);

        return $this->isPersian($text) && $confidence >= 0.5 ? $text : null;
    }

    private function isPersian(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1 && ! str_starts_with($text, 'MYMEMORY WARNING');
    }

    private function sameWord(string $segment, string $word): bool
    {
        $segment = preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $segment) ?? '';

        return mb_strtolower($segment) === mb_strtolower($word);
    }

    private function tidy(string $translation): string
    {
        $translation = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', trim($translation)) ?? '';

        return mb_substr($translation, 0, 120);
    }
}
