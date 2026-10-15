<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around dictionaryapi.dev, the free English dictionary (no
 * key). It knows the base form of most words and some inflections, but
 * gives every sense of a word with no regard for the sentence it was met
 * in — the learner picks the one that fits. Only used through WordLookup,
 * which caches what it returns for every learner.
 */
class DictionaryApiClient
{
    use UsesOutboundProxy;

    private const BASE_URL = 'https://api.dictionaryapi.dev/api/v2/entries/en/';

    /** Senses kept per part of speech, and parts of speech kept per word. */
    private const MAX_DEFINITIONS = 3;

    private const MAX_PARTS_OF_SPEECH = 4;

    /**
     * The word's phonetic spelling, a pronunciation file and its meanings
     * grouped by part of speech; null when the dictionary does not know it
     * (a 404). Any other failure — no connection, a timeout, a 5xx or a
     * rate-limit — throws, so the caller can tell "unknown word" apart from
     * "could not ask" and not cache the second.
     *
     * @return array{phonetic: ?string, audio_url: ?string, meanings: list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>}|null
     */
    public function define(string $word): ?array
    {
        $url = self::BASE_URL.rawurlencode($word);

        $response = $this->withOutboundProxy(Http::connectTimeout(3)->timeout(6), $url)
            ->get($this->outboundUrl($url));

        if ($response->notFound()) {
            return null;
        }

        $entries = $response->throw()->json();

        if (! is_array($entries) || $entries === []) {
            return null;
        }

        return [
            'phonetic' => $this->phonetic($entries),
            'audio_url' => $this->audioUrl($entries),
            'meanings' => $this->meanings($entries),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function phonetic(array $entries): ?string
    {
        foreach ($entries as $entry) {
            $text = $entry['phonetic'] ?? collect($entry['phonetics'] ?? [])->pluck('text')->filter()->first();

            if (filled($text)) {
                return (string) $text;
            }
        }

        return null;
    }

    /**
     * The first pronunciation file, preferring an American recording since
     * the app reads words aloud in en-US too.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function audioUrl(array $entries): ?string
    {
        $files = collect($entries)
            ->flatMap(fn (array $entry) => $entry['phonetics'] ?? [])
            ->pluck('audio')
            ->filter(fn ($audio) => filled($audio) && str_starts_with((string) $audio, 'https://'));

        return $files->first(fn (string $audio) => str_contains($audio, '-us.')) ?? $files->first();
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>
     */
    private function meanings(array $entries): array
    {
        return collect($entries)
            ->flatMap(fn (array $entry) => $entry['meanings'] ?? [])
            ->filter(fn (array $meaning) => filled($meaning['partOfSpeech'] ?? null) && filled($meaning['definitions'] ?? null))
            ->take(self::MAX_PARTS_OF_SPEECH)
            ->map(fn (array $meaning) => [
                'pos' => (string) $meaning['partOfSpeech'],
                'definitions' => collect($meaning['definitions'])
                    ->filter(fn (array $definition) => filled($definition['definition'] ?? null))
                    ->take(self::MAX_DEFINITIONS)
                    ->map(fn (array $definition) => [
                        'definition' => (string) $definition['definition'],
                        'example' => filled($definition['example'] ?? null) ? (string) $definition['example'] : null,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
