<?php

namespace App\Services;

use RuntimeException;

/**
 * The dictionary's second opinion: asks Gemini for the meanings and the
 * Persian translation of one word when Wiktionary cannot be reached
 * or has no entry for the form that was tapped (a plural, "overslept",
 * "running"). Slower and quota-bound compared with the dictionary, so
 * WordLookup only turns to it then, and caches what it says like any other
 * answer. Returns the same meanings shape as WiktionaryClient.
 */
class GeminiWordDefiner
{
    private const MAX_MEANINGS = 4;

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @return array{meanings: list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>, translation_fa: ?string}
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function define(string $word): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => $word]],
            systemPrompt: $this->systemPrompt(),
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! is_array($data['meanings'] ?? null)) {
            throw new RuntimeException('Unexpected word-definition format from Gemini.');
        }

        return [
            'meanings' => $this->group($data['meanings']),
            'translation_fa' => filled($data['translation_fa'] ?? null) && is_string($data['translation_fa'])
                ? mb_substr(trim($data['translation_fa']), 0, 120)
                : null,
        ];
    }

    /**
     * The Persian equivalents of $word for each part of speech it can be,
     * in natural Persian a learner would recognise — the "different
     * meanings" the sheet shows in place of MyMemory's single, often poor
     * word. The English senses are given so Gemini translates the senses
     * the sheet actually lists instead of guessing at the word.
     *
     * @param  list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>  $meanings
     * @return list<array{pos: string, words: string}>
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function persianMeanings(string $word, array $meanings): array
    {
        $senses = collect($meanings)
            ->map(fn (array $group) => $group['pos'].': '.collect($group['definitions'])->pluck('definition')->implode(' / '))
            ->implode("\n");

        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Word: {$word}\n".($senses !== '' ? "English senses:\n{$senses}" : '')]],
            systemPrompt: 'You translate English dictionary words into natural Persian for Persian-speaking English learners. '
                .'The user sends one English word and, usually, its English senses grouped by part of speech. Reply with ONLY '
                .'valid JSON, no markdown fences, shaped exactly like: {"persian": [{"pos": "verb", "words": "..."}]}. '
                .'Give one entry per part of speech listed (at most '.self::MAX_MEANINGS.'), most common first. "words" is '
                .'one to three common Persian equivalents of the word in that part of speech, in Persian script, separated '
                .'by "، " — the everyday words an educated speaker would use, not transliterations and not explanations. '
                .'If the word is an inflected form (a plural, a past tense, an -ing form), translate the form as written. '
                .'If the text is not an English word, reply {"persian": []}.',
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! is_array($data['persian'] ?? null)) {
            throw new RuntimeException('Unexpected Persian-meanings format from Gemini.');
        }

        return collect($data['persian'])
            ->filter(fn ($item) => is_array($item) && filled($item['pos'] ?? null) && filled($item['words'] ?? null)
                && preg_match('/\p{Arabic}/u', (string) $item['words']) === 1)
            ->take(self::MAX_MEANINGS)
            ->map(fn (array $item) => [
                'pos' => mb_strtolower(trim((string) $item['pos'])),
                'words' => mb_substr(trim((string) $item['words']), 0, 120),
            ])
            ->values()
            ->all();
    }

    private function systemPrompt(): string
    {
        return 'You are a learner\'s dictionary for English learners at A2-B1 level whose first language is Persian. '
            .'The user sends ONE English word. Reply with ONLY valid JSON, no markdown fences, shaped exactly like: '
            .'{"meanings": [{"pos": "noun", "definition": "...", "example": "..."}], "translation_fa": "..."}. '
            .'Give at most '.self::MAX_MEANINGS.' meanings, the most common first. "pos" is the part of speech in English '
            .'("noun", "verb", "adjective", "adverb", "preposition", ...). "definition" is one short, simple English '
            .'sentence a B1 learner understands, without using the word itself. "example" is one natural everyday '
            .'sentence that uses the word. If the word is an inflected form (a plural, a past tense, an -ing form), '
            .'say so in the definition, e.g. "past tense of oversleep: to sleep longer than you meant to". '
            .'"translation_fa" is the most common Persian equivalent of the word as written, at most three, in '
            .'Persian script separated by "، ". If the text is not an English word, reply {"meanings": [], "translation_fa": null}.';
    }

    private function withoutCodeFence(string $raw): string
    {
        return trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)) ?? '');
    }

    /**
     * @param  array<int, mixed>  $meanings
     * @return list<array{pos: string, definitions: list<array{definition: string, example: ?string}>}>
     */
    private function group(array $meanings): array
    {
        return collect($meanings)
            ->filter(fn ($meaning) => is_array($meaning) && filled($meaning['pos'] ?? null) && filled($meaning['definition'] ?? null))
            ->take(self::MAX_MEANINGS)
            ->groupBy(fn (array $meaning) => mb_strtolower(trim((string) $meaning['pos'])))
            ->map(fn ($group, string $pos) => [
                'pos' => $pos,
                'definitions' => $group->map(fn (array $meaning) => [
                    'definition' => trim((string) $meaning['definition']),
                    'example' => filled($meaning['example'] ?? null) ? trim((string) $meaning['example']) : null,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
