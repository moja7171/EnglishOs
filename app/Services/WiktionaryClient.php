<?php

namespace App\Services;

use App\Services\Concerns\UsesOutboundProxy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Thin wrapper around Wiktionary's public definition API (no key), the
 * English meanings behind the tap-a-word sheet. Chosen over dictionaryapi.dev
 * after checking both live: that one hung for most words, this one answers in
 * about half a second and also lists inflected forms ("overslept" is
 * explained as the past tense of "oversleep"). It gives every sense of the
 * word with no regard for the sentence it was met in — the learner picks
 * the one that fits. Only used through WordLookup, which caches the result
 * for every learner.
 */
class WiktionaryClient
{
    use UsesOutboundProxy;

    private const BASE_URL = 'https://en.wiktionary.org/api/rest_v1/page/definition/';

    /** Wikimedia asks API clients to say who they are. */
    private const USER_AGENT = 'EnglishOS/1.0 (https://englishos.growwise.ir; English learning app)';

    /** Senses kept per part of speech, and parts of speech kept per word. */
    private const MAX_DEFINITIONS = 3;

    private const MAX_PARTS_OF_SPEECH = 4;

    private const MAX_DEFINITION_LENGTH = 220;

    /**
     * Asks Wiktionary about $word on the given request (a plain one, or one
     * made by Http::pool() so WordLookup can ask the translation service at
     * the same time) and returns what that sends back, to be read by parse().
     */
    public function fetch(PendingRequest $request, string $word): mixed
    {
        $url = self::BASE_URL.rawurlencode($word);

        return $this->withOutboundProxy($request->withUserAgent(self::USER_AGENT)->connectTimeout(2)->timeout(4), $url)
            ->get($this->outboundUrl($url));
    }

    /**
     * The word's meanings grouped by part of speech; null when Wiktionary
     * has no English entry for it. That is only a 404 with Wiktionary's own
     * JSON body — a 404 from anything else (a relay, a proxy error page)
     * throws like every other failure, so "could not ask" is never mistaken
     * for "unknown word" and cached as one.
     *
     * @return array{phonetic: null, audio_url: null, meanings: list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>}|null
     */
    public function define(string $word): ?array
    {
        return $this->parse($this->fetch(Http::withOptions([]), $word));
    }

    /**
     * @return array{phonetic: null, audio_url: null, meanings: list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>}|null
     */
    public function parse(mixed $response): ?array
    {
        if (! $response instanceof Response) {
            throw $response instanceof Throwable ? $response : new RuntimeException('No response from the service.');
        }

        if ($response->notFound() && $response->json('status') === 404) {
            return null;
        }

        $sections = $response->throw()->json('en');

        if (! is_array($sections)) {
            return null;
        }

        $meanings = collect($sections)
            ->map(fn (array $section) => [
                'pos' => mb_strtolower($this->plain((string) ($section['partOfSpeech'] ?? ''))),
                'definitions' => collect($section['definitions'] ?? [])
                    ->map(fn (array $definition) => [
                        'definition' => Str::limit($this->plain((string) ($definition['definition'] ?? '')), self::MAX_DEFINITION_LENGTH),
                        'example' => $this->plain((string) ($definition['examples'][0] ?? '')) ?: null,
                    ])
                    ->filter(fn (array $definition) => $definition['definition'] !== '')
                    ->take(self::MAX_DEFINITIONS)
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $meaning) => $meaning['pos'] !== '' && $meaning['definitions'] !== [])
            ->take(self::MAX_PARTS_OF_SPEECH)
            ->values()
            ->all();

        return $meanings === [] ? null : ['phonetic' => null, 'audio_url' => null, 'meanings' => $meanings];
    }

    /** Wiktionary sends its definitions as HTML with links and labels. */
    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
