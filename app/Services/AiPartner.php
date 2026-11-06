<?php

namespace App\Services;

use App\Models\AiPractice;
use App\Models\PairGame;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Teddy, the AI classmate: what he says in a game. He talks like a
 * student at the learner's own level, in short, correct sentences, and
 * never teaches. Card answers need no AI: they are the deck's own
 * sentences. His made-up life is the same in every game.
 */
class AiPartner
{
    public const NAME = 'Teddy';

    private const PERSONA = 'You are Teddy, a cheerful, talkative young man who loves travel and food. You live in Shiraz with your parents '
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
     * Just a Minute, Teddy's turn: his short talk on the topic, in his own
     * life, using the card's words.
     *
     * @param  list<string>  $words
     */
    public function talkAbout(string $topic, array $words, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: {$topic}\nWords to use: ".implode(', ', $words)]],
            systemPrompt: $this->classmate($learnerDescription).' Talk about the topic in about 5 short sentences, from your own life. '
                .'Use every word in the list, naturally. Plain text only, no lists.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }

    /**
     * Just a Minute, the learner's turn: one real question about what they
     * just said.
     */
    public function questionAbout(string $topic, string $talk, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: {$topic}\nYour classmate said: \"{$talk}\""]],
            systemPrompt: $this->classmate($learnerDescription).' This is an automatic transcript of speech, so be kind to small mishearings. '
                .'Reply with ONLY one short question about something they said, at most 12 words, and nothing before or after it. Plain text only.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }

    /**
     * Just a Minute, Teddy's turn: his answer to the learner's question
     * about his talk.
     */
    public function replyToQuestion(string $talk, string $question, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "You said: \"{$talk}\"\nYour classmate asks: \"{$question}\""]],
            systemPrompt: $this->classmate($learnerDescription).' The question is an automatic transcript of speech, so be kind to small mishearings. '
                .'Answer in one or two short sentences. Plain text only.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }

    /**
     * Story Chain: Teddy's next line of the story told so far, with his
     * word in it. His last line ends the story.
     */
    public function continueStory(string $story, string $word, bool $endsTheStory, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => "The story so far:\n{$story}\n\nYour word: {$word}"]],
            systemPrompt: $this->classmate($learnerDescription).' You and your classmate are telling a made-up story, taking turns. '
                .'Their lines are automatic transcripts of speech, so be kind to small mishearings. Reply with ONLY the next one or two short sentences of the story, using your word naturally, '
                .($endsTheStory ? 'and give the story a happy or funny ending.' : 'and leave the story open for your classmate.').' Plain text only.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }

    /**
     * Two Truths, Teddy's turn: three things about his made-up life, one
     * of them a lie. Their order is shuffled here: models keep putting the
     * lie in the same place.
     *
     * @return array{statements: list<string>, lie: int}
     *
     * @throws RuntimeException when the reply isn't the expected JSON.
     */
    public function threeThings(string $topic, ?string $hint, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: {$topic}".($hint ? "\nGrammar to use: {$hint}" : '')]],
            systemPrompt: $this->classmate($learnerDescription).' In the game Two Truths and a Lie, say three short things about yourself on the topic: two true for your life, one made up but believable. '
                .'Reply with ONLY valid JSON, no markdown fences: {"statements": ["...", "...", "..."], "lie": 1, 2 or 3}.',
            profile: GeminiClient::PROFILE_CHAT,
        );

        $data = AiJson::decode($raw);
        $statements = is_array($data) ? array_values(array_filter($data['statements'] ?? [], fn ($line) => is_string($line) && filled($line))) : [];

        if (count($statements) !== PairGame::STATEMENTS || ! in_array($data['lie'] ?? null, range(1, PairGame::STATEMENTS), true)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        $lie = trim($statements[$data['lie'] - 1]);
        $shuffled = Arr::shuffle(array_map(trim(...), $statements));

        return ['statements' => $shuffled, 'lie' => array_search($lie, $shuffled, true) + 1];
    }

    /**
     * Two Truths, Teddy's turn: his answer to the learner's question, which
     * keeps the lie safe.
     *
     * @param  list<string>  $statements
     */
    public function keepTheLieSafe(array $statements, int $lie, string $question, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => 'You said: '.json_encode($statements, JSON_UNESCAPED_UNICODE)."\nNumber {$lie} is your lie.\nYour classmate asks: \"{$question}\""]],
            systemPrompt: $this->classmate($learnerDescription).' You are playing Two Truths and a Lie. The question is an automatic transcript of speech, so be kind to small mishearings. '
                .'Answer in one short sentence as if everything you said were true. Never say which one is the lie. Plain text only.',
            profile: GeminiClient::PROFILE_CHAT,
        ));
    }

    /**
     * Two Truths, the learner's turn: which of their three things Teddy
     * thinks is the lie, and why.
     *
     * @return array{pick: int, line: string}
     *
     * @throws RuntimeException when the reply isn't the expected JSON.
     */
    public function findTheLie(string $topic, string $heard, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "Topic: {$topic}\nYour classmate said: \"{$heard}\""]],
            systemPrompt: $this->classmate($learnerDescription).' You are playing Two Truths and a Lie: your classmate said three things, one is a lie. This is an automatic transcript of speech, so be kind to small mishearings. '
                .'Guess the lie. Reply with ONLY valid JSON, no markdown fences: {"pick": 1, 2 or 3, "line": "one short sentence saying your guess and why"}.',
            profile: GeminiClient::PROFILE_CHAT,
        );

        $data = AiJson::decode($raw);

        if (! is_array($data) || ! in_array($data['pick'] ?? null, range(1, PairGame::STATEMENTS), true) || blank($data['line'] ?? null)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return ['pick' => $data['pick'], 'line' => trim($data['line'])];
    }

    /**
     * Taboo, the learner's turn: Teddy's guess at the word they are
     * explaining. Whether it's right is checked with no AI call.
     *
     * @param  list<array{said: string, guess: string}>  $tries
     */
    public function guessTheWord(string $explanation, array $tries, string $learnerDescription): string
    {
        return trim($this->gemini->chat(
            [['role' => 'user', 'text' => 'Earlier: '.json_encode($tries, JSON_UNESCAPED_UNICODE)."\nYour classmate says: \"{$explanation}\""]],
            systemPrompt: $this->classmate($learnerDescription).' In a word game your classmate explains a word without saying it. This is an automatic transcript of speech, so be kind to small mishearings. '
                .'Never repeat a wrong guess. Reply with ONLY your guess: one word or a short phrase, no sentence, no punctuation.',
            profile: GeminiClient::PROFILE_CHAT,
        ), " \t\n\r\0\x0B.\"'");
    }

    /**
     * Taboo, Teddy's turn: three clues for the word in one call, so the next
     * clue costs nothing. A clue that lets the word slip is dropped.
     *
     * @return list<string>
     *
     * @throws RuntimeException when the reply isn't the expected JSON or no clue is left.
     */
    public function cluesFor(string $word, string $learnerDescription): array
    {
        $raw = $this->gemini->chat(
            [['role' => 'user', 'text' => "The word: {$word}"]],
            systemPrompt: $this->classmate($learnerDescription).' In a word game you explain a word without saying it, so your classmate can guess it. '
                .'Give three clues, from hard to easy, each ONE short sentence. Never use the word or a part of it. Reply with ONLY valid JSON, no markdown fences: {"clues": ["...", "...", "..."]}.',
            profile: GeminiClient::PROFILE_CHAT,
        );

        $data = AiJson::decode($raw);
        $clues = collect(is_array($data) ? ($data['clues'] ?? []) : [])
            ->filter(fn ($clue) => is_string($clue) && filled($clue) && AiPractice::wordsIn($clue, [$word]) === [])
            ->map(fn (string $clue) => trim($clue))
            ->values()
            ->all();

        if ($clues === []) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $clues;
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
