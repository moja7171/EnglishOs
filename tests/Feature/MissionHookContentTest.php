<?php

namespace Tests\Feature;

use App\Models\Mission;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MissionHookContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_m01_step_has_a_non_empty_hook(): void
    {
        $this->seed(MissionSeeder::class);

        $mission = Mission::where('code', 'M01')->firstOrFail();

        foreach ($mission->stepKeys() as $key) {
            $hook = $mission->stepContent($key)['hook'] ?? null;

            $this->assertNotEmpty($hook, "Step \"{$key}\" is missing a hook.");
        }
    }

    /**
     * Matches the exact page order in document/M01/Mission01.pdf (page 2's
     * "3-Day Plan" + pages 06-12): Active Recall and Error Log are pages
     * 09-10, both *before* the Final Challenge on page 11 — not after it.
     * The app itself splits the book's single Partner/AI day into two
     * balanced phases (Practice, then Challenge — see MissionSeeder), but
     * the underlying step order still matches the book exactly. Several
     * things are app-only additions, not part of the original book: a
     * daily_listen_N gate (see DailyListenStep) that now opens every day
     * after Day 1 for daily ear training, reading_comprehension (the
     * 2026-09-03 UX-review Story C), inserted right before Writing — read
     * a model text about someone else's day, then write your own —
     * video_shadowing (2026-09-03), appended to the end of Day 2: watch a
     * real YouTube video with captions then without, then shadow a line —
     * and two image-based speaking steps added 2026-09-04: story_sequence
     * right after Grammar in Context (narrate a picture sequence in the
     * Present Simple tense just taught) and picture_description right
     * after AI Feedback #1 (a real CEFR "describe this picture" task —
     * the only step that practices describing a scene rather than the
     * learner's own routine).
     */
    public function test_m01_step_order_matches_the_real_3_day_plan(): void
    {
        $this->seed(MissionSeeder::class);

        $mission = Mission::where('code', 'M01')->firstOrFail();

        $this->assertSame([
            // Day 1 · Individual (pages 01-03)
            'mission_brief',
            'vocabulary_builder',
            'listening',
            // Day 2 · Individual (pages 04-05)
            'daily_listen_2',
            'grammar_in_context',
            'story_sequence',
            'activation',
            'video_shadowing',
            // Day 3 · Partner/AI (pages 06-12), split into Practice + Challenge
            'daily_listen_3',
            'ai_conversation_1',
            'ai_feedback_1',
            'picture_description',
            'reading_comprehension',
            'writing',
            'daily_listen_4',
            'active_recall',
            'error_log',
            'ai_conversation_2',
            'mission_result',
        ], $mission->stepKeys());
    }
}
