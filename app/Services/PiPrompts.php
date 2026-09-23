<?php

namespace App\Services;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;

/**
 * Builds ready-to-paste prompts for Pi (or any other live-voice AI
 * assistant) — a separate app with no API of ours to call, so the whole
 * mechanism is "tell the learner exactly what to paste where". Same
 * "build it from the mission's own seeded content" approach the old
 * ProgramPlanner::speaking() used, generalized to 3 fixed personas
 * (teacher/partner/pronunciation coach) distributed across the steps
 * they fit, instead of one prompt on a single consolidation day.
 *
 * Every *Task() method fails soft (returns null) when the mission has no
 * usable content for that slot, same convention as PexelsClient — a
 * caller just skips rendering the card.
 *
 * Content is deliberately written as a live spoken exchange (Pi asks,
 * waits, reacts, continues — a game/role-play/quick-fire round) rather
 * than a "send text, get graded" loop: Pi is a live-voice assistant, not
 * a chat interface, and a "give me 3 sentences to check" style prompt
 * reads as a text drill even when spoken aloud. Low-pressure, playful
 * framing ("game", "no big deal", "fun not a test") is intentional too —
 * these are optional extra-practice cards, not evaluated Evidence, so
 * they should feel inviting at every CEFR level, never like a test.
 */
class PiPrompts
{
    public const ROLE_TEACHER = 'teacher';

    public const ROLE_PARTNER = 'partner';

    public const ROLE_COACH = 'coach';

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
                'when' => 'Grammar practice, whenever a mission asks you to try a new structure.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please be my English grammar coach — but keep it playful and spoken, more like '
                    .'a quick game than a lesson. When I bring you a grammar point, turn it into a short back-'
                    .'and-forth: ask me a few real questions that need it, one at a time, wait for my spoken '
                    .'answer, and react like we\'re actually talking, not grading a test. If I make a mistake, '
                    .'give me one short, friendly fix and keep going — never a long list, never anything that '
                    .'feels like an exam. Ready?',
            ],
            self::ROLE_PARTNER => [
                'label' => 'Language Partner',
                'when' => 'Live spoken conversation practice, in Listening and speaking steps.',
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
                'when' => 'Shadowing steps, to get real feedback on how you actually sound.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please be my pronunciation coach. When I give you lines to practice, say each '
                    .'one aloud the way a native speaker naturally would, let me repeat it back, then give me '
                    .'one quick, specific fix — the exact sound or stress to adjust, never just \'try again\' — '
                    .'and move straight on. Keep the pace snappy and encouraging, like a fun drill, never a '
                    .'test. Ready?',
            ],
        ];
    }

    /**
     * @return array{instruction: string, prompt: string}|null
     */
    public function teacherTask(Mission $mission): ?array
    {
        $focus = $mission->stepContent('grammar_in_context')['focus'] ?? null;

        if (! $focus) {
            return null;
        }

        return [
            'instruction' => 'Go to your Teacher chat and try this:',
            'prompt' => "Let's practice \"{$focus}\" as a quick spoken game, not a written test. Ask me 3 or 4 "
                .'real questions, one at a time, that need "'.$focus.'" to answer — wait for me to answer out '
                .'loud before the next one, and react like we\'re actually chatting. If I get the grammar '
                .'wrong, jump in gently with a one-line fix and keep going — no big deal, we\'re just playing '
                .'with it.',
        ];
    }

    /**
     * Shared by Video Shadowing and every Daily Listening step — both read
     * their own phase's `shadow_lines` (same key, different step key).
     *
     * @return array{instruction: string, prompt: string}|null
     */
    public function coachTask(MissionRun $run, string $stepKey): ?array
    {
        $lines = collect($run->mission->stepContent($stepKey)['shadow_lines'] ?? [])
            ->map(fn (string $line) => trim(str_replace('**', '', $line)))
            ->filter()
            ->values();

        if ($lines->isEmpty()) {
            return null;
        }

        return [
            'instruction' => 'Go to your Pronunciation Coach chat and try this:',
            'prompt' => 'Let\'s turn these into a quick echo game: '.$lines->implode(' / ').'. Say the first one '
                .'the way a native speaker naturally would — real speed, real rhythm — I\'ll repeat it right '
                .'back, then give me one quick, specific fix, nothing long, and we jump straight to the next '
                .'line. Keep the pace snappy and fun, like a game, not a test.',
        ];
    }

    /**
     * Shared by Listening (Day 1) and Talk It Out's warm-up round — both
     * are conversation-shaped and read the mission's own topic, but the
     * framing differs so the two don't feel like the same exercise twice:
     * Listening is the learner's first real exposure to the topic, so it
     * plays as a relaxed getting-to-know-the-topic chat grounded in the
     * episode's own phrases; Talk It Out's round comes right before the
     * main challenge, so it plays as a fast, playful warm-up instead.
     *
     * @return array{instruction: string, prompt: string}|null
     */
    public function partnerTask(MissionRun $run, string $stepKey): ?array
    {
        $mission = $run->mission;
        $topic = $mission->title;

        if (! $topic) {
            return null;
        }

        $phrases = collect($mission->stepContent($stepKey)['target_phrases'] ?? [])->pluck('phrase')->filter();

        if ($stepKey === 'listening') {
            $phraseHint = $phrases->isNotEmpty()
                ? ' Try to use these words if it feels natural: '.$phrases->implode(', ').'.'
                : '';

            return [
                'instruction' => 'Go to your Language Partner chat and try this:',
                'prompt' => "Let's get warmed up on today's topic — \"{$topic}\". Ask me a few easy, casual "
                    .'questions about my own life that connect to it, one at a time, and wait for me to answer '
                    .'out loud before the next one — like we just started chatting.'.$phraseHint,
            ];
        }

        $phraseHint = $phrases->isNotEmpty()
            ? ' If it fits naturally, try working in: '.$phrases->implode(', ').'.'
            : '';

        return [
            'instruction' => 'Go to your Language Partner chat and try this:',
            'prompt' => "Before I dive into the real challenge, let's do a 2-minute warm-up on \"{$topic}\": fire "
                .'off quick, easy questions one at a time — nothing deep, just fast spoken answers to loosen me '
                .'up — and keep the energy quick and fun.'.$phraseHint,
        ];
    }
}
