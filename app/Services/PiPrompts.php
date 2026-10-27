<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\User;
use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * Builds ready-to-paste prompts for Pi (or any other live-voice AI
 * assistant) — a separate app with no API of ours to call, so the whole
 * mechanism is "tell the learner exactly what to paste where", built from
 * the mission's title and grammar.
 *
 * The prompts are used once a day, outside the mission steps, on the /pi
 * practice page (see App\Services\PiPractice). All 4 days of a mission are
 * the same light speaking practice — a short question-and-answer chat
 * about the mission's topic where the AI and the learner take turns asking
 * — and each day only gives the questions its own flavor (see DAYS). No
 * question is written into the prompt: the AI makes up fresh ones, and a
 * random set of life areas (see ANGLES) gives each session a new angle,
 * so a day practiced twice doesn't repeat itself. Nothing comes from the
 * mission's listening, video or reading — only its topic.
 *
 * Every prompt is self-contained — the learner's level, the rules and the
 * plan all travel in it — because Pi keeps a single running conversation
 * (no separate chats per role) and a voice session may start fresh. The
 * AI plays a warm, encouraging buddy, but the rules keep a session short:
 * one cheerful opening line and no small talk (a voice session otherwise
 * opens with "how are you?"), 1-2 short sentences per turn, one very short
 * fix per turn, numbered rounds so it ends after about 10 minutes, and a
 * fixed wrap-up. The AI can't track time, so the length is set in rounds,
 * not minutes. The control words count only when said alone — "next" or
 * "again" inside an answer must not skip or repeat a round.
 *
 * Pi can't copy a whole chat at once, so the AI also sends the learner's
 * own words, uncorrected, in one message — at the wrap-up and whenever
 * the learner says "transcript" — for them to paste back for feedback.
 * That feedback is judged against the day's own goal (see dayTask()'s
 * `feedback`).
 */
class PiPrompts
{
    /**
     * Asks Android to open Pi in its app — a plain link from the installed
     * web app opens Pi's website, which blocks many of our learners. The
     * page appends a fallback back to itself (see piAndroidIntentJs()) so
     * a phone that can't open the app stays put instead of landing on the
     * blocked site.
     */
    public const PI_ANDROID_INTENT = 'intent://pi.ai#Intent;scheme=https;package=ai.inflection.pi';

    /**
     * The JavaScript expression for PI_ANDROID_INTENT with a fallback to
     * the current page.
     */
    public static function piAndroidIntentJs(): string
    {
        return "'".self::PI_ANDROID_INTENT.";S.browser_fallback_url=' + encodeURIComponent(location.href.split('#')[0] + '#pi') + ';end'";
    }

    /**
     * The flavor of each day's chat: what the learner sees it called, the
     * one-line goal, the kind of questions the AI asks and the kind the
     * learner asks back.
     *
     * @var array<int, array{title: string, goal: string, questions: string, myQuestions: string}>
     */
    public const DAYS = [
        1 => [
            'title' => 'About You',
            'goal' => 'A quick chat about this mission\'s topic and your own life. You answer 4 questions and ask 4.',
            'questions' => 'Ask about my own life and habits: what, where, when, who, how often.',
            'myQuestions' => 'I ask about your life.',
        ],
        2 => [
            'title' => 'Grammar Chat',
            'goal' => 'A quick chat about this mission\'s topic, using this mission\'s grammar. You answer 4 questions and ask 4.',
            'questions' => 'Ask questions whose natural answer needs "{grammar}". Don\'t explain the grammar.',
            'myQuestions' => 'I try to use "{grammar}" in my question too.',
        ],
        3 => [
            'title' => 'Your Opinion',
            'goal' => 'A quick chat about what you think of this mission\'s topic, and why. You answer 4 questions and ask 4.',
            'questions' => 'Ask what I think: Do you like…? Which is better, A or B? Why?',
            'myQuestions' => 'I ask what you think.',
        ],
        4 => [
            'title' => 'Your Experiences',
            'goal' => 'A quick chat about things you have done around this mission\'s topic. You answer 4 questions and ask 4.',
            'questions' => 'Ask about things I have really done: Have you ever…? When was the last time…? What happened?',
            'myQuestions' => 'I ask about your experiences.',
        ],
    ];

    /**
     * The words the learner can say to steer a session — listed in every
     * prompt and on the practice page.
     *
     * @var array<string, string>
     */
    public const CONTROL_WORDS = [
        'next' => 'skip to the next round',
        'again' => 'say it again',
        'slower' => 'speak more slowly',
        'why?' => 'explain a bit more',
        'stop' => 'end the session now',
        'more' => 'keep going after the end',
        'transcript' => 'get all my words in one message',
    ];

    /** The rounds of every session: the AI asks in odd rounds, the learner in even ones. */
    private const ROUNDS = 8;

    /**
     * Parts of everyday life that give the topic a fresh angle — a random
     * few per session, one per question of the AI.
     *
     * @var list<string>
     */
    private const ANGLES = [
        'home', 'work or school', 'family', 'friends', 'free time', 'food',
        'my town', 'weekends', 'travel', 'phones and the internet', 'money', 'health',
    ];

    /**
     * The practice for one day (1-4) of one mission, with everything the
     * practice page shows about it. `$seed` picks the session's angles, so
     * the page can keep the same prompt while it is open; without one,
     * every call gets new angles.
     *
     * `feedback` is the judging note for the pasted-back transcript (see
     * LearnerFeedback::review()'s $extraGuidance).
     *
     * @return array{title: string, goal: string, plan: list<string>, prompt: string, feedback: string}
     */
    public function dayTask(User $learner, string $missionCode, int $dayNumber, ?int $seed = null): array
    {
        $dayNumber = isset(self::DAYS[$dayNumber]) ? $dayNumber : 1;
        $mission = Mission::where('code', $missionCode)->first();
        $catalog = Mission::roadmapCatalog()[$missionCode] ?? ['title' => $missionCode, 'grammar' => ''];
        $title = $mission?->title ?? $catalog['title'];
        $grammar = $mission?->stepContent('grammar_in_context')['focus'] ?? $catalog['grammar'];
        $grammar = $grammar !== '' ? $grammar : $title;
        $day = self::DAYS[$dayNumber];

        return [
            'title' => $day['title'],
            'goal' => $day['goal'],
            'plan' => [
                'Start — the AI says hi and asks the first question (1 min)',
                self::ROUNDS.' rounds — the AI asks, you answer; then you ask, the AI answers (7 min)',
                'Wrap-up — 2 fixes and 1 thing you did well (2 min)',
            ],
            'prompt' => $this->prompt($learner, $day, $title, $grammar, $this->angles($seed)),
            'feedback' => $this->feedback($dayNumber, $title, $grammar),
        ];
    }

    /**
     * One random life area for each question of the AI.
     *
     * @return list<string>
     */
    private function angles(?int $seed): array
    {
        $randomizer = new Randomizer($seed === null ? new Secure : new Mt19937($seed));

        return array_slice($randomizer->shuffleArray(self::ANGLES), 0, intdiv(self::ROUNDS, 2));
    }

    /**
     * @param  array{title: string, goal: string, questions: string, myQuestions: string}  $day
     * @param  list<string>  $angles
     */
    private function prompt(User $learner, array $day, string $title, string $grammar, array $angles): string
    {
        $level = $learner->cefr_level ?: 'B1';
        $rounds = self::ROUNDS;
        $controlWords = collect(self::CONTROL_WORDS)
            ->map(fn (string $meaning, string $word) => "\"{$word}\" = {$meaning}")
            ->implode(', ');
        $questions = str_replace('{grammar}', $grammar, $day['questions']);
        $myQuestions = str_replace('{grammar}', $grammar, $day['myQuestions']);

        return "I'm an English learner at {$level} level. Let's have a 10-minute voice chat.\n\n"
            ."Be my warm, fun English buddy. Use simple, everyday words.\n\n"
            ."Rules for you:\n"
            ."- Start with one short, cheerful line, then begin. Don't ask how I am. No small talk.\n"
            ."- Keep every turn short: 1-2 simple sentences.\n"
            ."- If I make a mistake, say the correct sentence and the reason in 8 words or less. Only one fix per turn. Otherwise just a few kind words.\n"
            ."- Say the round number each time, like \"Round 1 of {$rounds}\".\n"
            ."- My control words count only when I say one alone, as my whole turn: {$controlWords}.\n"
            ."- After round {$rounds}, stop. Give me my 2 most useful fixes, each with a very short reason, and 1 thing I did well. Then, in the same message, add my transcript and only ask: \"More?\"\n"
            ."- My transcript: ONE message, never split. Write \"My words:\", then one line per round: \"Round 1 — Q: (your question, 6 words max) / Me: (everything I said)\" or \"Round 2 — Me: (my question)\". Copy my words exactly — do NOT fix my mistakes. Send it after the last round and whenever I say \"transcript\".\n\n"
            ."Today: {$day['title']} — every question is about \"{$title}\".\n"
            ."- Odd rounds (1, 3, 5, 7): ask me ONE new, short question. {$questions} Never repeat a question you already asked me in this chat.\n"
            ."- Even rounds (2, 4, 6, 8): say \"Your turn!\" and give me a 2-4 word idea. {$myQuestions} Answer like a real person in 1-2 sentences. If my question had a mistake, fix it.\n"
            .'- Fresh angles for your questions, one each, in this order: '.implode(', ', $angles).".\n\n"
            .'Start now: say your cheerful line, then begin round 1.';
    }

    /**
     * What the pasted-back transcript is judged on: the learner's answers
     * and their own questions, plus the day's own focus.
     */
    private function feedback(int $dayNumber, string $title, string $grammar): string
    {
        $dayFocus = match ($dayNumber) {
            2 => "Each answer should use \"{$grammar}\": one that needs it and avoids it or gets it wrong is a \"fix\" whose pattern names it.",
            3 => 'Each answer should give an opinion with a reason (else the "extend" technique).',
            4 => 'Talking about past experiences needs the past simple and "Have you ever…?" — fix tense mistakes first.',
            default => 'Answers are about the learner\'s own life, mostly in the present simple.',
        };

        return "TODAY'S TASK is a simple CONVERSATION about \"{$title}\": in odd rounds the learner answers the AI's "
            .'question, in even rounds the learner asks the AI a question of their own. Judge both: were the answers '
            .'more than a few words (else the "extend" technique), were the learner\'s own questions correctly formed '
            .'(word order, do/does/did — a wrong one is a "fix"), and did they ask their 4 questions (else "ask_back"). '
            .$dayFocus.' The summary is about how well they kept the chat going.';
    }
}
