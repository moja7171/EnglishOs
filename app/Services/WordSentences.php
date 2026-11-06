<?php

namespace App\Services;

use App\Models\User;
use App\Models\VocabularyWord;
use App\Models\WordSentence;
use Throwable;

/**
 * The sentences a learner learns a notebook word from: short and simple
 * ones the AI makes for it (at most VocabularyWord::SIMPLE_SENTENCE_WORDS
 * words, with the word in them), and a check of one the learner wrote.
 * The AI is never what holds the learner up: every call here returns null
 * or a plain answer when it fails.
 */
class WordSentences
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * A new simple sentence for the word, saved. Null when the AI can't
     * make a good one (it must be short and have the word in it).
     */
    public function makeOne(VocabularyWord $word, User $learner): ?WordSentence
    {
        $have = collect($word->learningSentences())->pluck('text')->all();

        for ($try = 0; $try < 2; $try++) {
            try {
                $sentence = trim($this->gemini->chat(
                    [['role' => 'user', 'text' => "Word: {$word->word}".($word->meaning ? "\nMeaning: {$word->meaning}" : '').($have !== [] ? "\nDo not repeat: ".json_encode($have, JSON_UNESCAPED_UNICODE) : '')]],
                    systemPrompt: 'You write one example sentence for an English learner who is '.$learner->levelDescription().'. '
                        .'Use the word in its most common meaning. The sentence must be SIMPLE so it can be learned by heart: at most '.VocabularyWord::SIMPLE_SENTENCE_WORDS.' words, everyday words, present or past simple, one clear picture. '
                        .'Reply with ONLY the sentence, nothing before or after it.',
                    profile: GeminiClient::PROFILE_CHAT,
                ), " \t\n\r\0\x0B\"'");
            } catch (Throwable) {
                return null;
            }

            if ($sentence !== '' && VocabularyWord::isSimple($sentence) && $word->splitAround($sentence) !== null && ! in_array($sentence, $have, true)) {
                return $word->sentences()->create(['sentence' => $sentence, 'source' => WordSentence::SOURCE_AI]);
            }
        }

        return null;
    }

    /**
     * Checks a sentence the learner wrote with the word: saved when the
     * word is used and the AI finds nothing wrong, or saved with the
     * AI's corrected version. Null when it can't be checked.
     *
     * @return array{ok: bool, used: bool, better: ?string, note: string}|null
     */
    public function check(VocabularyWord $word, string $sentence, User $learner): ?array
    {
        $sentence = trim($sentence);

        if (str_word_count($sentence) < 3 || mb_strlen($sentence) > 200) {
            return ['ok' => false, 'used' => false, 'better' => null, 'note' => 'Write a full sentence.'];
        }

        if ($word->splitAround($sentence) === null) {
            return ['ok' => false, 'used' => false, 'better' => null, 'note' => "Use “{$word->word}” in your sentence."];
        }

        try {
            $raw = $this->gemini->chat(
                [['role' => 'user', 'text' => "Word: {$word->word}\nSentence: \"{$sentence}\""]],
                systemPrompt: 'You check one sentence written by an English learner who is '.$learner->levelDescription().' that should use the given word. '
                    .'Decide if the sentence is correct and uses the word correctly. Reply with ONLY valid JSON, no markdown fences: '
                    .'{"ok": true or false, "better": "the corrected sentence, only when ok is false, else null", "note": "one short, kind line in English"}.',
                profile: GeminiClient::PROFILE_CHAT,
            );
        } catch (Throwable) {
            return null;
        }

        $data = AiJson::decode($raw);

        if (! is_array($data) || ! array_key_exists('ok', $data)) {
            return null;
        }

        $better = ($data['ok'] ?? false) === true ? null : (filled($data['better'] ?? null) ? trim($data['better']) : null);

        $word->sentences()->create([
            'sentence' => $sentence,
            'source' => WordSentence::SOURCE_OWN,
            'better' => $better,
            'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
        ]);

        return ['ok' => $better === null, 'used' => true, 'better' => $better, 'note' => trim((string) ($data['note'] ?? ''))];
    }
}
