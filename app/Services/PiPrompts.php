<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Builds ready-to-paste prompts for Pi (or any other live-voice AI
 * assistant) — a separate app with no API of ours to call, so the whole
 * mechanism is "tell the learner exactly what to paste where". Same
 * "build it from the mission's own seeded content" approach the old
 * ProgramPlanner::speaking() used, generalized to 3 fixed personas
 * (teacher/partner/pronunciation coach).
 *
 * The personas are used once a day, outside the mission steps, on the
 * /pi practice page (see App\Services\PiPractice): each of a mission's 4
 * days has its own fixed practice — pronunciation, grammar, conversation,
 * retell — built by dayTask(). Every day always returns a prompt: when the
 * mission lacks the content a day would normally use, it falls back to a
 * shorter prompt built from the mission's title or roadmap grammar, so the
 * page never has an empty card.
 *
 * Content is deliberately written as a live spoken exchange (Pi asks,
 * waits, reacts, continues — a game/role-play/quick-fire round) rather
 * than a "send text, get graded" loop: Pi is a live-voice assistant, not
 * a chat interface, and a "give me 3 sentences to check" style prompt
 * reads as a text drill even when spoken aloud. Low-pressure, playful
 * framing ("game", "no big deal", "fun not a test") is intentional too —
 * this is optional extra practice, not evaluated Evidence, so it should
 * feel inviting at every CEFR level, never like a test.
 */
class PiPrompts
{
    public const ROLE_TEACHER = 'teacher';

    public const ROLE_PARTNER = 'partner';

    public const ROLE_COACH = 'coach';

    /**
     * The fixed practice of each day of a mission: which of the 3 Pi chats
     * it happens in, what the learner sees it called, and the one-line goal.
     *
     * @var array<int, array{role: string, label: string, title: string, goal: string}>
     */
    public const DAYS = [
        1 => [
            'role' => self::ROLE_COACH,
            'label' => 'Pronunciation Coach',
            'title' => 'Pronunciation',
            'goal' => 'Say this day\'s new words and phrases out loud, and get one quick, specific fix on each.',
        ],
        2 => [
            'role' => self::ROLE_TEACHER,
            'label' => 'Teacher',
            'title' => 'Grammar',
            'goal' => 'Play with the mission\'s grammar point out loud — a spoken game, not a written test.',
        ],
        3 => [
            'role' => self::ROLE_PARTNER,
            'label' => 'Language Partner',
            'title' => 'Conversation',
            'goal' => 'A short, easy role-play on the mission\'s topic — fast spoken answers, nothing deep.',
        ],
        4 => [
            'role' => self::ROLE_TEACHER,
            'label' => 'Teacher',
            'title' => 'Retell & Fix',
            'goal' => 'Tell the story of this mission in your own words, then get your 3 most useful fixes.',
        ],
    ];

    /**
     * The most example lines the pronunciation day hands to the coach in
     * one go — enough for a quick echo game, few enough to finish.
     */
    private const MAX_PRONUNCIATION_LINES = 6;

    /**
     * The one-time setup message for each of the 3 persistent Pi chats —
     * shown on /pi-setup. Content is final (not a placeholder), written
     * in English since Pi is an English-speaking assistant.
     *
     * @return array<string, array{label: string, when: string, setupMessage: string}>
     */
    public function onboardingRoles(User $learner): array
    {
        $level = $learner->cefr_level;

        return [
            self::ROLE_TEACHER => [
                'label' => 'Teacher',
                'when' => 'Grammar and Retell & Fix days of your daily voice practice.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please be my English grammar coach — but keep it playful and spoken, more like '
                    .'a quick game than a lesson. When I bring you a grammar point, turn it into a short back-'
                    .'and-forth: ask me a few real questions that need it, one at a time, wait for my spoken '
                    .'answer, and react like we\'re actually talking, not grading a test. If I make a mistake, '
                    .'give me one short, friendly fix and keep going — never a long list, never anything that '
                    .'feels like an exam. Keep your own language at my level. Ready?',
            ],
            self::ROLE_PARTNER => [
                'label' => 'Language Partner',
                'when' => 'Conversation day of your daily voice practice.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please be a friendly English conversation partner. When I ask you to talk about '
                    .'something, keep it light and spoken — ask me one question at a time, wait for my spoken '
                    .'answer, react naturally, then follow up, like a real conversation, never an interview. '
                    .'Feel free to turn it into a quick game sometimes too — would-you-rather, two truths and a '
                    .'lie, a mini role-play, building a story together — whatever keeps it fun. Keep your own '
                    .'language at my level. Ready?',
            ],
            self::ROLE_COACH => [
                'label' => 'Pronunciation Coach',
                'when' => 'Pronunciation day of your daily voice practice.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please be my pronunciation coach. When I give you lines to practice, say each '
                    .'one aloud the way a native speaker naturally would, let me repeat it back, react like '
                    .'we\'re actually practicing together, then give me one quick, specific fix — the exact '
                    .'sound or stress to adjust, never just \'try again\' — and move straight on. Keep your own '
                    .'language at my level, and keep the pace snappy and encouraging, like a fun game, never a '
                    .'test. Ready?',
            ],
        ];
    }

    /**
     * The practice for one day (1-4) of one mission, with everything the
     * practice page shows about it.
     *
     * @return array{role: string, roleLabel: string, title: string, goal: string, instruction: string, prompt: string}
     */
    public function dayTask(string $missionCode, int $dayNumber): array
    {
        $day = self::DAYS[$dayNumber] ?? self::DAYS[1];
        $mission = Mission::where('code', $missionCode)->first();
        $catalog = Mission::roadmapCatalog()[$missionCode] ?? ['title' => $missionCode, 'grammar' => ''];
        $title = $mission?->title ?? $catalog['title'];

        $prompt = match ($dayNumber) {
            2 => $this->grammarPrompt($mission?->stepContent('grammar_in_context')['focus'] ?? $catalog['grammar'], $title),
            3 => $this->conversationPrompt($title, $this->targetPhrases($mission)),
            4 => $this->retellPrompt($title),
            default => $this->pronunciationPrompt($title, $this->pronunciationLines($mission, $dayNumber)),
        };

        return [
            'role' => $day['role'],
            'roleLabel' => $day['label'],
            'title' => $day['title'],
            'goal' => $day['goal'],
            'instruction' => "Go to your {$day['label']} chat and try this:",
            'prompt' => $prompt,
        ];
    }

    /**
     * What the day's steps give the coach to say aloud: the shadowing
     * lines when the day has them, otherwise the example sentences of the
     * words the learner just met, otherwise the mission's target phrases.
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
     * @return list<string>
     */
    private function targetPhrases(?Mission $mission): array
    {
        return collect($mission?->stepContent('ai_conversation_1')['target_phrases'] ?? [])
            ->pluck('phrase')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $lines
     */
    private function pronunciationPrompt(string $title, array $lines): string
    {
        if ($lines === []) {
            return "Like always, let's play a quick echo game on \"{$title}\": make up a few short, useful sentences "
                .'about it and say the first one the way a native speaker naturally would — real speed, real '
                .'rhythm — I\'ll repeat it right back, then give me one quick, specific fix, nothing long, and we '
                .'jump straight to the next one. Keep the pace snappy and fun, like a game, not a test.';
        }

        return 'Like always, let\'s turn these into a quick echo game: '.implode(' / ', $lines).'. Say the first one '
            .'the way a native speaker naturally would — real speed, real rhythm — I\'ll repeat it right '
            .'back, then give me one quick, specific fix, nothing long, and we jump straight to the next '
            .'line. Keep the pace snappy and fun, like a game, not a test.';
    }

    private function grammarPrompt(string $focus, string $title): string
    {
        $focus = $focus !== '' ? $focus : $title;

        return "Like always, let's practice \"{$focus}\" as a quick spoken game, not a written test. Ask me 3 or 4 "
            .'real questions, one at a time, that need "'.$focus.'" to answer — wait for me to answer out '
            .'loud before the next one, and react like we\'re actually chatting. If I get the grammar '
            .'wrong, jump in gently with a one-line fix and keep going — no big deal, we\'re just playing '
            .'with it.';
    }

    /**
     * @param  list<string>  $phrases
     */
    private function conversationPrompt(string $title, array $phrases): string
    {
        $phraseHint = $phrases !== []
            ? ' If it fits naturally, try working in: '.implode(', ', $phrases).'.'
            : '';

        return "Like always, let's do a short spoken role-play about \"{$title}\": you pick a real-life situation around "
            .'it and play your part, and I\'ll play mine. Fire off quick, easy questions one at a time — nothing '
            .'deep, just fast spoken answers — react like we\'re really chatting, and keep the energy quick and '
            .'fun.'.$phraseHint;
    }

    private function retellPrompt(string $title): string
    {
        return "Like always, let's play a retell game about \"{$title}\". I'll tell you in my own words what I learned and "
            .'did — no notes — and you just listen, no fixing while I talk. Then ask me 2 or 3 follow-up '
            .'questions, one at a time, and I\'ll answer out loud. At the end, give me my 3 most useful fixes '
            .'and 3 better phrases I could use next time — quick and friendly, nothing long, like a game, not '
            .'a test.';
    }
}
