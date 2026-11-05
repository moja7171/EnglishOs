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
 * the mission's title.
 *
 * The prompts are used once a day, outside the mission steps, on the /pi
 * practice page (see App\Services\PiPractice). All 4 days of a mission are
 * the same free discussion between two friends: the mission's topic and
 * the day's opening (see DAYS) only start the talk, then it follows the
 * learner. A random life area (see ANGLES) gives each session a new way
 * in, so the same topic doesn't bring the same questions back. Nothing
 * comes from the mission's listening, video or reading — only its topic.
 *
 * Every prompt is self-contained — the learner's level and the rules
 * travel in it — because Pi keeps a single running conversation (no
 * separate chats per role) and a voice session may start fresh. There are
 * no rounds, no transcript and no wrap-up: the learner talks until they
 * say "stop", and the page takes their word that they did (see
 * App\Models\User::recordPiPracticeToday()).
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
     * What makes each day's chat different: what the learner sees it
     * called, the one-line goal, and how the AI opens it.
     *
     * @var array<int, array{title: string, goal: string, opening: string}>
     */
    public const DAYS = [
        1 => [
            'title' => 'About You',
            'goal' => 'A free chat. Pi starts from your own daily life.',
            'opening' => 'Begin from my own daily life.',
        ],
        2 => [
            'title' => 'A Little Story',
            'goal' => 'A free chat. Pi starts with a very short story.',
            'opening' => 'Begin with a very short story of your own, then let me react.',
        ],
        3 => [
            'title' => 'Your Opinion',
            'goal' => 'A free chat. Pi starts with something to agree or disagree with.',
            'opening' => 'Begin by saying something I can agree or disagree with.',
        ],
        4 => [
            'title' => 'Your Experiences',
            'goal' => 'A free chat. Pi starts from things you have done.',
            'opening' => 'Begin from something I have really done.',
        ],
    ];

    /**
     * The words the learner can say to steer a session — listed in every
     * prompt and on the guide page.
     *
     * @var array<string, string>
     */
    public const CONTROL_WORDS = [
        'slower' => 'speak more slowly',
        'stop' => 'end the chat',
    ];

    /**
     * Parts of everyday life that give the topic a fresh way in — one
     * random area per session.
     *
     * @var list<string>
     */
    private const ANGLES = [
        'home', 'work or school', 'family', 'friends', 'free time', 'food',
        'my town', 'weekends', 'travel', 'phones and the internet', 'money', 'health',
    ];

    /**
     * The practice for one day (1-4) of one mission, with everything the
     * practice page shows about it. `$seed` picks the session's life area,
     * so the page can keep the same prompt while it is open; without one,
     * every call gets a new area.
     *
     * @return array{title: string, goal: string, plan: list<string>, prompt: string}
     */
    public function dayTask(User $learner, string $missionCode, int $dayNumber, ?int $seed = null): array
    {
        $dayNumber = isset(self::DAYS[$dayNumber]) ? $dayNumber : 1;
        $title = Mission::where('code', $missionCode)->value('title')
            ?? (Mission::roadmapCatalog()[$missionCode]['title'] ?? $missionCode);
        $day = self::DAYS[$dayNumber];

        return [
            'title' => $day['title'],
            'goal' => $day['goal'],
            'plan' => [
                'Pi says hi and starts the chat',
                'You talk freely for about '.PiPractice::MINUTES.' minutes',
                'Say "stop" when you\'re done',
            ],
            'prompt' => $this->prompt($learner, $day, $title, $this->angle($seed)),
        ];
    }

    /**
     * One random life area for the session.
     */
    private function angle(?int $seed): string
    {
        $randomizer = new Randomizer($seed === null ? new Secure : new Mt19937($seed));

        return self::ANGLES[$randomizer->getInt(0, count(self::ANGLES) - 1)];
    }

    /**
     * @param  array{title: string, goal: string, opening: string}  $day
     */
    private function prompt(User $learner, array $day, string $title, string $angle): string
    {
        $level = $learner->cefr_level ?: 'B1';

        return "I'm an English learner at {$level} level. Let's have a relaxed voice chat, like two friends.\n\n"
            ."Our starting point is \"{$title}\". {$day['opening']} Today's side of it: {$angle}. After that, follow what I say. The chat can go anywhere.\n\n"
            ."How to talk with me:\n"
            ."- You are my warm, fun friend, not a teacher or an interviewer. Use simple, everyday words.\n"
            ."- Start with one short, cheerful line. Don't ask how I am.\n"
            ."- Keep every turn short: 1-2 simple sentences. I should talk more than you.\n"
            ."- React to what I just said and share your own thoughts.\n"
            ."- Ask one question at most in a turn. Sometimes ask none.\n"
            ."- Never ask something I already answered, in any words. Don't go back to questions from our earlier chats.\n"
            ."- If I make a mistake, don't stop me. Just use the correct words naturally in your reply. Explain only if I ask.\n"
            ."- Keep going until I say \"stop\". If I say \"slower\", speak more slowly.\n\n"
            .'Start now.';
    }
}
