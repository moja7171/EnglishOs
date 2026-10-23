<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds ready-to-paste prompts for Pi (or any other live-voice AI
 * assistant) — a separate app with no API of ours to call, so the whole
 * mechanism is "tell the learner exactly what to paste where", built from
 * the mission's own seeded content.
 *
 * The prompts are used once a day, outside the mission steps, on the /pi
 * practice page (see App\Services\PiPractice): each of a mission's 4 days
 * has its own fixed practice — pronunciation, grammar, conversation,
 * retell — built by dayTask(). Every day always returns a prompt: when the
 * mission lacks the content a day would normally use, it falls back to a
 * shorter prompt built from the mission's title or roadmap grammar, so the
 * page never has an empty card.
 *
 * Every prompt is self-contained — the learner's level, the rules, the
 * plan and the material all travel in it — because Pi keeps a single
 * running conversation (no separate chats per role) and a voice session
 * may start fresh. The AI plays a warm, encouraging buddy, but the rules
 * keep a session short and specific: one cheerful opening line and no
 * small talk (a voice session otherwise opens with "how are you?"), 1-2
 * short sentences per turn, one very short fix per turn, numbered rounds
 * so it ends after about 10 minutes, and a fixed wrap-up. The AI can't
 * track time, so the length is set in rounds, not minutes. The control
 * words count only when said alone — "next" or "again" inside an answer
 * must not skip or repeat a round.
 *
 * Pi can't copy a whole chat at once, so the AI also sends the learner's
 * own words, uncorrected, in one message — at the wrap-up and whenever
 * the learner says "transcript" — for them to paste back for feedback.
 * That feedback is judged against the day's own goal (see dayTask()'s
 * `feedback`): a pronunciation drill is checked line against line, never
 * for grammar the learner only repeated.
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
     * The fixed practice of each day of a mission: what the learner sees it
     * called and the one-line goal.
     *
     * @var array<int, array{title: string, goal: string}>
     */
    public const DAYS = [
        1 => [
            'title' => 'Pronunciation',
            'goal' => 'Repeat short lines from this mission out loud and find the words that are hard to understand.',
        ],
        2 => [
            'title' => 'Grammar',
            'goal' => 'Answer 5 quick questions out loud with this mission\'s grammar.',
        ],
        3 => [
            'title' => 'Conversation',
            'goal' => 'A short chat about this mission\'s topic — 6 quick turns, 2 of them your questions.',
        ],
        4 => [
            'title' => 'Retell & Fix',
            'goal' => 'Retell one story from this mission in your own words, then get your 3 most useful fixes.',
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

    /**
     * The most example lines the pronunciation day hands to the AI in one
     * go — enough for a quick echo round, few enough to finish in time.
     */
    private const MAX_PRONUNCIATION_LINES = 6;

    /**
     * The most suggested questions and target phrases the conversation day
     * hands over — a guide, not a script.
     */
    private const MAX_CONVERSATION_HINTS = 4;

    /** One transcript line on the days the AI asks and the learner answers. */
    private const QUESTION_TRANSCRIPT = 'Round 1 — Q: (your question, 6 words max) / Me: (everything I said)';

    /**
     * The practice for one day (1-4) of one mission, with everything the
     * practice page shows about it.
     *
     * `feedback` is the judging note for the pasted-back transcript (see
     * LearnerFeedback::review()'s $extraGuidance), and `isPronunciationDrill`
     * says the learner only repeated given lines.
     *
     * @return array{title: string, goal: string, plan: list<string>, prompt: string, feedback: string, isPronunciationDrill: bool}
     */
    public function dayTask(User $learner, string $missionCode, int $dayNumber): array
    {
        $dayNumber = isset(self::DAYS[$dayNumber]) ? $dayNumber : 1;
        $mission = Mission::where('code', $missionCode)->first();
        $catalog = Mission::roadmapCatalog()[$missionCode] ?? ['title' => $missionCode, 'grammar' => ''];
        $title = $mission?->title ?? $catalog['title'];

        $session = match ($dayNumber) {
            2 => $this->grammarSession($mission?->stepContent('grammar_in_context')['focus'] ?? $catalog['grammar'], $title),
            3 => $this->conversationSession($mission, $title),
            4 => $this->retellSession($mission, $title),
            default => $this->pronunciationSession($title, $this->pronunciationLines($mission, $dayNumber)),
        };

        $day = self::DAYS[$dayNumber];

        return [
            'title' => $day['title'],
            'goal' => $day['goal'],
            'plan' => $session['plan'],
            'prompt' => $this->prompt($learner, $day['title'], $title, $session),
            'feedback' => $session['feedback'],
            'isPronunciationDrill' => $dayNumber === 1,
        ];
    }

    /**
     * What the day's steps give the AI to say aloud: the shadowing lines
     * when the day has them, otherwise the example sentences of the words
     * the learner just met, otherwise the mission's target phrases.
     * (Day 1 is comprehension-only, so it normally uses the new words.)
     *
     * @return list<string>
     */
    private function pronunciationLines(?Mission $mission, int $dayNumber): array
    {
        $steps = collect($mission?->phases[$dayNumber - 1]['steps'] ?? [])->filter(fn ($step) => is_array($step));

        $fromShadowing = $steps
            ->flatMap(fn (array $step) => $step['shadow_lines'] ?? [])
            ->map(fn (string $line) => trim(str_replace('**', '', $line)));

        $fromWords = $steps
            ->flatMap(fn (array $step) => collect($step['words'] ?? [])->pluck('example'))
            ->map(fn ($example) => trim((string) $example));

        $fromPhrases = $steps
            ->flatMap(fn (array $step) => collect($step['target_phrases'] ?? [])->pluck('phrase'))
            ->map(fn ($phrase) => trim((string) $phrase));

        return collect([$fromShadowing, $fromWords, $fromPhrases])
            ->map(fn (Collection $lines) => $lines->filter()->unique()->values())
            ->first(fn (Collection $lines) => $lines->isNotEmpty(), collect())
            ->take(self::MAX_PRONUNCIATION_LINES)
            ->all();
    }

    /**
     * The phrases the mission's listening and video taught — the ones worth
     * pulling into the conversation.
     *
     * @return list<string>
     */
    private function targetPhrases(?Mission $mission): array
    {
        return collect(['listening', 'video_shadowing'])
            ->flatMap(fn (string $stepKey) => collect($mission?->stepContent($stepKey)['target_phrases'] ?? [])->pluck('phrase'))
            ->filter()
            ->unique()
            ->take(self::MAX_CONVERSATION_HINTS)
            ->values()
            ->all();
    }

    /**
     * The shared head (level, rules) and tail (start now) wrapped around a
     * day's own plan and material.
     *
     * @param  array{rounds: int, fixes: int, plan: list<string>, steps: list<string>, material: string, transcript: string, feedback: string}  $session
     */
    private function prompt(User $learner, string $dayTitle, string $missionTitle, array $session): string
    {
        $level = $learner->cefr_level ?: 'B1';
        $controlWords = collect(self::CONTROL_WORDS)
            ->map(fn (string $meaning, string $word) => "\"{$word}\" = {$meaning}")
            ->implode(', ');
        $steps = collect($session['steps'])
            ->map(fn (string $step, int $index) => ($index + 1).'. '.$step)
            ->implode("\n");

        return "I'm an English learner at {$level} level. This is a 10-minute voice practice.\n\n"
            ."Be my warm, fun English buddy — friendly, casual, and happy to help me. Use simple, everyday words.\n\n"
            ."Rules for you:\n"
            ."- Start with one short, cheerful line (like \"Hey! Ready? Let's go!\"), then begin. Don't ask how I am. No small talk.\n"
            ."- Keep every turn short: 1-2 simple sentences. No long explanations.\n"
            ."- After each answer, cheer me on in a few real words (\"Nice!\", \"Love that!\").\n"
            ."- If I make a mistake, say something kind first, then the correct sentence and the reason in 8 words or less. Only one fix per turn.\n"
            ."- Say the round number each time, like \"Round 1 of {$session['rounds']}\".\n"
            ."- My control words count only when I say one alone, as my whole turn: {$controlWords}.\n"
            ."- After the last round, stop. Give me my {$session['fixes']} most useful fixes, each with a very short reason, and 1 thing I did well. End with one short line to keep me going. Then, in the same message, add my transcript and only ask: \"More?\"\n"
            ."- My transcript: ONE message, never split. Write \"My words:\", then one line per round: \"{$session['transcript']}\". Copy my words exactly — do NOT fix my mistakes. Send it after the last round and whenever I say \"transcript\".\n\n"
            ."Today: {$dayTitle} — \"{$missionTitle}\".\n"
            .$steps."\n"
            .($session['material'] !== '' ? "\n".$session['material']."\n" : '')
            ."\nStart now: say your cheerful line, tell me the plan in one short sentence, then begin round 1.";
    }

    /**
     * The transcript keeps each line whole ("Line:", not a 6-word "Q:"), so
     * the feedback can compare what was heard with what was meant.
     *
     * @param  list<string>  $lines
     * @return array{rounds: int, fixes: int, plan: list<string>, steps: list<string>, material: string, transcript: string, feedback: string}
     */
    private function pronunciationSession(string $title, array $lines): array
    {
        $count = $lines === [] ? self::MAX_PRONUNCIATION_LINES : count($lines);
        $targets = $lines === []
            ? 'The target lines are the "Line:" parts of the transcript.'
            : 'The target lines: '.collect($lines)->map(fn (string $line) => "\"{$line}\"")->implode(' ').' (the "Line:" parts of the transcript say the same).';

        return [
            'rounds' => $count + 1,
            'fixes' => 2,
            'plan' => [
                'Start — the AI tells you the plan (1 min)',
                "{$count} lines — the AI says one, you repeat it; then all of them in a row (7 min)",
                'Wrap-up — 2 fixes and 1 thing you did well (2 min)',
            ],
            'steps' => [
                ($lines === []
                    ? "Make up {$count} short, useful lines about \"{$title}\". In rounds 1 to {$count}, say one line at natural speed, and I repeat it."
                    : "In rounds 1 to {$count}, say one line below at natural speed, and I repeat it."),
                'After I repeat it, tell me if every word came through clearly. If not, name the unclear word and say it slowly once.',
                'In round '.($count + 1).', I say all the lines in a row.',
            ],
            'material' => $lines === []
                ? ''
                : "Lines:\n".collect($lines)->map(fn (string $line, int $index) => ($index + 1).'. '.$line)->implode("\n"),
            'transcript' => 'Round 1 — Line: (the whole line you said) / Me: (everything I said)',
            'feedback' => 'TODAY\'S TASK is a repeat-after-me PRONUNCIATION drill: the learner only repeated given lines, '
                .'so NEVER judge their grammar or vocabulary and never praise or fault "the grammar". '.$targets.' '
                .'The learner\'s lines were written down by speech-to-text, so a word that is missing, changed or '
                .'misheard compared with the target line most likely did not come through clearly. Mark a learner line '
                .'"ok" when it matches its target (ignore punctuation and capitals), else "fix" with "corrected" = the '
                .'target line, "why" = which word(s) did not come through and one short Persian tip on how to say that '
                .'sound, "pattern" = "pronunciation". Summary, positives and '
                .'negatives are only about clarity and the sounds. No techniques. better_version: null.',
        ];
    }

    /**
     * @return array{rounds: int, fixes: int, plan: list<string>, steps: list<string>, material: string, transcript: string, feedback: string}
     */
    private function grammarSession(string $focus, string $title): array
    {
        $focus = $focus !== '' ? $focus : $title;

        return [
            'rounds' => 5,
            'fixes' => 2,
            'plan' => [
                'Start — the AI tells you the plan and gives 1 example (1 min)',
                '5 questions — answer each one out loud (7 min)',
                'Wrap-up — 2 fixes and 1 thing you did well (2 min)',
            ],
            'steps' => [
                "Before round 1, give me one short example sentence with \"{$focus}\".",
                "In rounds 1 to 5, ask me one real question about my life that needs \"{$focus}\". Wait for my answer, cheer me on in a few words, then fix or move on.",
            ],
            'material' => '',
            'transcript' => self::QUESTION_TRANSCRIPT,
            'feedback' => "TODAY'S TASK is GRAMMAR practice: each answer should use \"{$focus}\". Judge mainly that: an answer "
                ."that needs \"{$focus}\" and avoids it or gets it wrong is a \"fix\" whose pattern names it. The summary "
                .'says in one line how well the learner used it. Mention other mistakes only when they block understanding.',
        ];
    }

    /**
     * @return array{rounds: int, fixes: int, plan: list<string>, steps: list<string>, material: string, transcript: string, feedback: string}
     */
    private function conversationSession(?Mission $mission, string $title): array
    {
        $questions = collect($mission?->stepContent('ai_conversation_1')['interview_questions'] ?? [])
            ->filter()
            ->take(self::MAX_CONVERSATION_HINTS)
            ->values();
        $phrases = $this->targetPhrases($mission);

        $material = collect([
            $questions->isNotEmpty() ? "Questions you can ask me:\n".$questions->map(fn (string $question) => "- {$question}")->implode("\n") : null,
            $phrases !== [] ? 'Phrases I should try to use: '.implode(', ', $phrases).'. Ask questions that make me need them.' : null,
        ])->filter()->implode("\n\n");

        return [
            'rounds' => 6,
            'fixes' => 2,
            'plan' => [
                'Start — the AI tells you the plan (1 min)',
                '6 turns — the AI asks, you answer; in turns 3 and 6 you ask (7 min)',
                'Wrap-up — 2 fixes and 1 thing you did well (2 min)',
            ],
            'steps' => [
                "We have a casual chat about \"{$title}\"."
                    .($mission?->outcome ? ' My goal: '.Str::finish($mission->outcome, '.') : ''),
                'In rounds 1, 2, 4 and 5, ask me one question. Wait for my answer, cheer me on in a few words, then fix or move on.',
                'In rounds 3 and 6, I ask you a question instead. Answer in 1-2 sentences.',
            ],
            'material' => $material,
            'transcript' => self::QUESTION_TRANSCRIPT,
            'feedback' => "TODAY'S TASK is a CONVERSATION about \"{$title}\": the learner answers in full and asks 2 questions "
                .'of their own (rounds 3 and 6). Judge that first: did they ask their 2 questions (else the "ask_back" '
                .'technique), were the answers long enough (else "extend")'
                .($phrases !== [] ? ', and which of these phrases they used (positives) or missed (negatives): '.implode(', ', $phrases) : '')
                .'. The summary is about how well they kept the chat going.',
        ];
    }

    /**
     * The stories the learner can retell: the first sentence of what the
     * mission's listening, video and reading were about.
     *
     * @return list<string>
     */
    private function retellStories(?Mission $mission): array
    {
        return collect(['listening', 'video_shadowing', 'reading_comprehension'])
            ->map(fn (string $stepKey) => (string) ($mission?->stepContent($stepKey)['topic_summary'] ?? ''))
            ->filter()
            ->map(fn (string $summary) => preg_split('/(?<=[.!?])\s+/', trim($summary))[0])
            ->values()
            ->all();
    }

    /**
     * @return array{rounds: int, fixes: int, plan: list<string>, steps: list<string>, material: string, transcript: string, feedback: string}
     */
    private function retellSession(?Mission $mission, string $title): array
    {
        $stories = $this->retellStories($mission);

        return [
            'rounds' => 3,
            'fixes' => 3,
            'plan' => [
                'Retell — 1-2 minutes in your own words, no interruptions (3 min)',
                '2 follow-up questions — answer out loud (4 min)',
                'Wrap-up — 3 fixes and 1 thing you did well (3 min)',
            ],
            'steps' => [
                $stories === []
                    ? "In round 1, I tell you in my own words what I learned about \"{$title}\". Just listen — no fixing while I talk."
                    : 'In round 1, I pick one story below and retell it in my own words. Just listen — no fixing while I talk.',
                'In rounds 2 and 3, ask me one follow-up question about it. Wait for my answer, cheer me on in a few words.',
            ],
            'material' => $stories === []
                ? ''
                : "Stories:\n".collect($stories)->map(fn (string $story) => "- {$story}")->implode("\n"),
            'transcript' => self::QUESTION_TRANSCRIPT,
            'feedback' => "TODAY'S TASK is a RETELL of one story from \"{$title}\""
                .($stories === [] ? '' : ' ('.implode(' / ', $stories).')')
                .'. Judge that first: past tenses, a clear order (else the "sequence" technique) and whether the main '
                .'points of the story were told — name a missing one in negatives. The summary is about the retell.',
        ];
    }
}
