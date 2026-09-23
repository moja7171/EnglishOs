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
                    .'this chat, please act as my English grammar and sentence-building teacher. Whenever I show '
                    ."you a sentence, tell me if it's correct, explain briefly why in simple terms if it isn't, "
                    .'and suggest a more natural way to say it. Keep corrections short and encouraging — one main '
                    .'point at a time, never a long list. Ready?',
            ],
            self::ROLE_PARTNER => [
                'label' => 'Language Partner',
                'when' => 'Live spoken conversation practice, in Listening and speaking steps.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please act as a friendly English conversation partner. When I ask you to talk '
                    .'about a topic, ask me one question at a time, wait for my spoken answer, react naturally, '
                    .'then ask a natural follow-up — like a real conversation, not an interview. Keep your own '
                    .'language at my level. Ready?',
            ],
            self::ROLE_COACH => [
                'label' => 'Pronunciation Coach',
                'when' => 'Shadowing steps, to get real feedback on how you actually sound.',
                'setupMessage' => "Hi! I'm learning English, currently around {$level} level. From now on in "
                    .'this chat, please act as my pronunciation coach. When I ask you to, read a sentence or '
                    .'phrase aloud clearly, then listen to me repeat it, and tell me specifically which sound or '
                    ."stress pattern to adjust — be precise about the sound, not just 'try again'. Ready?",
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
            'prompt' => "I'm practicing \"{$focus}\" today. Give me 3 example sentences using it, then ask me to "
                .'write 3 of my own so you can check them.',
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
            'prompt' => 'Here are some phrases I\'m practicing today: '.$lines->implode(' / ').'. Read the first '
                ."one aloud, I'll repeat it, then tell me what to fix — then move to the next one.",
        ];
    }

    /**
     * Shared by Listening (Day 1) and Talk It Out's warm-up round — both
     * are conversation-shaped and read the mission's own topic. Listening
     * additionally grounds a few real phrases from its own episode.
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
        $phraseHint = $phrases->isNotEmpty()
            ? ' Try to use these words if it feels natural: '.$phrases->implode(', ').'.'
            : '';

        return [
            'instruction' => 'Go to your Language Partner chat and try this:',
            'prompt' => "Let's talk about \"{$topic}\" for 5 minutes. Ask me one question at a time and wait for "
                .'my spoken answer.'.$phraseHint,
        ];
    }
}
