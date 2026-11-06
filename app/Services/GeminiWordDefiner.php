<?php

namespace App\Services;

use RuntimeException;

/**
 * The dictionary's second opinion: asks Gemini for the meanings and the
 * Persian translation of one word when Wiktionary cannot be reached
 * or has no entry for the form that was tapped (a plural, "overslept",
 * "running"), and for the Persian of a phrase it does not list. Slower and quota-bound compared with the dictionary, so
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
            systemPrompt: 'You translate English dictionary words and phrases into natural Persian for Persian-speaking English learners. '
                .'The user sends one English word or fixed phrase (a phrasal verb such as "give up", an idiom) and, usually, its '
                .'English senses grouped by part of speech. Reply with ONLY '
                .'valid JSON, no markdown fences, shaped exactly like: {"persian": [{"pos": "verb", "words": "..."}]}. '
                .'Give one entry per part of speech listed (at most '.self::MAX_MEANINGS.'), most common first. "words" is '
                .'one to three common Persian equivalents of the word in that part of speech, in Persian script, separated '
                .'by "، " — the everyday words an educated speaker would use, not transliterations and not explanations. '
                .'If the word is an inflected form (a plural, a past tense, an -ing form), translate the form as written. '
                .'If the text is not an English word or phrase, reply {"persian": []}.',
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

    /**
     * The Persian of an English phrase the dictionary does not list ("went
     * to the shop"), as it is meant in $sentence — the one line the sheet
     * shows for it. null when Gemini says it is not English.
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function translatePhrase(string $phrase, ?string $sentence = null): ?string
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Phrase: {$phrase}".(filled($sentence) ? "\nSentence: {$sentence}" : '')]],
            systemPrompt: 'You translate short English phrases into natural Persian for Persian-speaking English learners at A2-B1 level. '
                .'The user sends a phrase and, usually, the sentence it comes from. Translate ONLY the phrase, with the meaning it '
                .'has in that sentence, the way an educated Persian speaker would say it — not word by word, not a transliteration, '
                .'no explanation. Reply with ONLY valid JSON, no markdown fences, shaped exactly like: {"translation_fa": "..."}. '
                .'If the phrase is not English, reply {"translation_fa": null}.',
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! array_key_exists('translation_fa', $data)) {
            throw new RuntimeException('Unexpected phrase-translation format from Gemini.');
        }

        $translation = is_string($data['translation_fa']) ? trim($data['translation_fa']) : '';

        return preg_match('/\p{Arabic}/u', $translation) === 1 ? mb_substr($translation, 0, 120) : null;
    }

    /**
     * The Persian a word or phrase has in the meaning and sentence it was
     * met in — one short line per item, the way an educated Persian speaker
     * would say it, not word by word. Several items go in one call (the
     * command that corrects words saved before this existed sends twenty at
     * a time). An item Gemini leaves out, or answers without Persian
     * letters, is missing from the result.
     *
     * @param  list<array{id: int|string, word: string, meaning: ?string, pos: ?string, sentence: ?string}>  $items
     * @return array<string, string> the Persian of each item, by the id it was sent with
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function persianInContext(array $items): array
    {
        $lines = collect($items)->map(fn (array $item) => json_encode(array_filter([
            'id' => (string) $item['id'],
            'word' => $item['word'],
            'part_of_speech' => $item['pos'] ?? null,
            'english_meaning' => $item['meaning'] ?? null,
            'sentence' => $item['sentence'] ?? null,
        ], filled(...)), JSON_UNESCAPED_UNICODE))->implode("\n");

        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => $lines]],
            systemPrompt: 'You translate English words and phrases into natural Persian for Persian-speaking English learners at A2-B1 level. '
                .'Each line the user sends is one JSON object: an English "word" (or phrase), usually its "english_meaning" and '
                .'"part_of_speech", and the "sentence" the learner met it in. Give the Persian of the word as it is used in THAT '
                .'meaning and sentence: one or two common equivalents, separated by "، ", the way an educated Persian speaker '
                .'would say it. Never word by word, never a transliteration, no explanation, no English. '
                .'Reply with ONLY valid JSON, no markdown fences, shaped exactly like: {"items": [{"id": "...", "persian": "..."}]}, '
                .'one entry per line, with the same "id". If a line is not English, leave its entry out.',
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            throw new RuntimeException('Unexpected in-context Persian format from Gemini.');
        }

        $asked = collect($items)->pluck('id')->map(fn ($id) => (string) $id)->all();

        return collect($data['items'])
            ->filter(fn ($item) => is_array($item) && in_array((string) ($item['id'] ?? ''), $asked, true)
                && is_string($item['persian'] ?? null) && preg_match('/\p{Arabic}/u', $item['persian']) === 1)
            ->mapWithKeys(fn (array $item) => [(string) $item['id'] => mb_substr(trim($item['persian']), 0, 120)])
            ->all();
    }

    /**
     * The Persian of many English words or phrases at once, for each part
     * of speech it can be — what the offline dictionary lacks (see the
     * dictionary:fill-persian command). A word Gemini leaves out (a name, an
     * inflected form, not English) is missing from the result.
     *
     * @param  list<string>  $words
     * @return array<string, list<array{pos: string, words: list<string>}>>
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function persianGlosses(array $words): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => implode("\n", $words)]],
            systemPrompt: 'You build an English -> Persian learner dictionary. The user sends English words or phrases, one per line. '
                .'For each one that is a base-form English word or a fixed phrase (a phrasal verb, an idiom), give its Persian '
                .'equivalents for each part of speech it commonly has (at most 3 parts of speech): the everyday words an educated '
                .'Persian speaker would use, most common first, at most 3 per part of speech, in Persian script — never a '
                .'transliteration, never an explanation. Leave a line out when it is a person\'s or place name, an inflected form '
                .'(a plural, a past tense, an -ing form), a contraction, or not English. Reply with ONLY valid JSON, no markdown '
                .'fences, shaped exactly like: {"entries": [{"word": "boost", "senses": [{"pos": "verb", "fa": ["افزایش دادن", "تقویت کردن"]}]}]}. '
                .'"pos" is one of: noun, verb, adjective, adverb, pronoun, preposition, conjunction, interjection, determiner, numeral, phrase.',
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! is_array($data['entries'] ?? null)) {
            throw new RuntimeException('Unexpected Persian-glosses format from Gemini.');
        }

        $asked = array_flip($words);
        $positions = ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection', 'determiner', 'numeral', 'phrase'];
        $glosses = [];

        foreach ($data['entries'] as $entry) {
            $word = is_array($entry) && is_string($entry['word'] ?? null) ? mb_strtolower(trim($entry['word'])) : null;

            if ($word === null || ! isset($asked[$word]) || ! is_array($entry['senses'] ?? null)) {
                continue;
            }

            $senses = collect($entry['senses'])
                ->filter(fn ($sense) => is_array($sense) && in_array($sense['pos'] ?? null, $positions, true) && is_array($sense['fa'] ?? null))
                ->map(fn (array $sense) => [
                    'pos' => $sense['pos'],
                    'words' => collect($sense['fa'])
                        ->filter(fn ($fa) => is_string($fa) && preg_match('/\p{Arabic}/u', $fa) === 1)
                        ->map(fn (string $fa) => mb_substr(trim($fa), 0, 60))
                        ->unique()
                        ->take(3)
                        ->values()
                        ->all(),
                ])
                ->filter(fn (array $sense) => $sense['words'] !== [])
                ->unique('pos')
                ->take(3)
                ->values()
                ->all();

            if ($senses !== []) {
                $glosses[$word] = $senses;
            }
        }

        return $glosses;
    }

    /**
     * How common an English phrase is for a learner: 3 = heard every day,
     * 2 = common, 1 = uncommon (formal, old, specialist). null when Gemini
     * does not give one of those. Single words are rated from a frequency
     * list instead (see WordFrequency).
     *
     * @throws RuntimeException when Gemini cannot be reached or answers with something that is not the agreed JSON
     */
    public function commonness(string $phrase): ?int
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => $phrase]],
            systemPrompt: 'You rate how common an English phrase (a phrasal verb, an idiom, a fixed expression) is in everyday '
                .'spoken and written English, for a learner. The user sends one phrase. Reply with ONLY valid JSON, no markdown '
                .'fences, shaped exactly like: {"commonness": 3}. 3 = very common, a native speaker says or hears it in '
                .'ordinary days ("give up", "look for"); 2 = common but not every day ("put up with"); 1 = uncommon: formal, '
                .'old-fashioned, literary or specialist ("bring to bear"). If the text is not an English phrase, reply {"commonness": null}.',
        );

        $data = json_decode($this->withoutCodeFence($raw), true);

        if (! is_array($data) || ! array_key_exists('commonness', $data)) {
            throw new RuntimeException('Unexpected commonness format from Gemini.');
        }

        return in_array($data['commonness'], [1, 2, 3], true) ? $data['commonness'] : null;
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
