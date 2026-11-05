<?php

namespace App\Services;

use RuntimeException;

/**
 * Tara, the AI classmate: what she says in a game. She talks like a
 * student at the learner's own level, in short, correct sentences, and
 * never teaches. Card answers need no AI: they are the deck's own
 * sentences. Her made-up life is the same in every game.
 */
class AiPartner
{
    public const NAME = 'Tara';

    private const PERSONA = 'You are Tara, a cheerful, talkative young woman who loves travel and food. You live in Shiraz with your parents '
        .'and your little brother, you work part-time at a small travel agency, and you are learning English too.';

    public function __construct(private readonly GeminiClient $gemini) {}

    private function classmate(string $learnerDescription): string
    {
        return self::PERSONA." You are a classmate of {$learnerDescription}, at the same level. Use short, simple, correct English and sound warm. Never teach or correct.";
    }

    /**
     * Who am I, with the AI holding the secret: its answer to the learner's
     * question, and whether the learner has just named the secret.
     *
     * @param  list<array{q: string, a: string}>  $asked
     * @return array{answer: string, guessed: bool}
     *
     * @throws RuntimeException when the reply isn't the expected JSON.
     */
    public function answerAboutSecret(string $secret, string $question, array $asked, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => 'Earlier: '.json_encode($asked, JSON_UNESCAPED_UNICODE)."\nNew question: \"{$question}\""]],
            systemPrompt: $this->classmate($learnerDescription)." In a guessing game you secretly are \"{$secret}\". The classmate asks yes/no questions, "
                .'as an automatic transcript of speech, so be kind to small mishearings. Reply with ONLY valid JSON, no markdown fences: '
                .'{"answer": "yes" or "no" or "sometimes", "guessed": true only if the question names the secret or something very close}.',
            profile: GeminiClient::PROFILE_CHAT,
        );

        $data = AiJson::decode($raw);

        if (! is_array($data) || ! in_array($data['answer'] ?? null, ['yes', 'no', 'sometimes'], true)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return ['answer' => $data['answer'], 'guessed' => ($data['guessed'] ?? false) === true];
    }

    /**
     * Who am I, with the learner holding the secret: the AI's next yes/no
     * question, a real guess once it has a good idea.
     *
     * @param  list<array{q: string, a: string}>  $asked
     *
     * @throws RuntimeException when the reply isn't the expected JSON.
     */
    public function nextQuestion(string $category, array $asked, string $learnerDescription): string
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => 'Questions so far: '.json_encode($asked, JSON_UNESCAPED_UNICODE)]],
            systemPrompt: $this->classmate($learnerDescription)." In a guessing game the classmate secretly is {$category}. Ask ONE new yes/no question starting "
                .'with Are you, Do you or Can you, or guess it (Are you a…?) when you have a good idea. Never repeat a question. Reply with ONLY valid JSON, no markdown fences: {"question": "..."}.',
            profile: GeminiClient::PROFILE_CHAT,
        );

        $data = AiJson::decode($raw);

        if (! is_array($data) || blank($data['question'] ?? null)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return trim($data['question']);
    }

    /**
     * Would You Rather: one sentence saying why the AI took its side.
     */
    public function reasonFor(string $question, string $side, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Would you rather: {$question}\nYou picked: {$side}"]],
            systemPrompt: $this->classmate($learnerDescription).' Say why in ONE short sentence, starting with "I\'d rather". Plain text only.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }
}
