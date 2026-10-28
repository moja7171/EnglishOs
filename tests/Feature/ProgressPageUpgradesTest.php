<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgressPageUpgradesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_is_one_scroll_without_tabs_or_the_cut_cards(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('progress.index')
            ->assertSee('This week')
            ->assertSee('Last 12 weeks')
            ->assertDontSee('Overview')
            ->assertDontSee('Memory freshness')
            ->assertDontSee('Vocabulary growth')
            ->assertDontSee('Your voice');
    }

    public function test_the_practice_tile_reflects_recorded_step_durations(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'Test Mission',
            'module' => 'Me',
            'outcome' => 'Outcome.',
            'phases' => [[
                'phase' => 'foundation',
                'steps' => [['key' => 'mission_brief', 'duration_minutes' => 5]],
            ]],
        ]);
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_TEXT, 'content_ref' => 'x']);

        $this->actingAs($learner);

        Livewire::test('progress.index')->assertSeeText('5m practice');
    }

    public function test_your_voice_lists_recorded_answers_newest_first_with_see_all_after_three(): void
    {
        $learner = User::factory()->create();

        foreach (range(1, 4) as $i) {
            $learner->speakingPrompts()->create([
                'mission_code' => 'M01',
                'prompt' => "Question {$i}?",
                'last_recording_url' => "/storage/voice-{$i}.webm",
                'last_reviewed_at' => now()->subDays(5 - $i),
                'next_review_at' => now()->addDay(),
            ]);
        }
        $learner->speakingPrompts()->create(['mission_code' => 'M01', 'prompt' => 'Never answered?', 'next_review_at' => now()]);

        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSee('Your voice')
            ->assertSeeInOrder(['Question 4?', 'Question 3?', 'Question 2?', 'Question 1?'])
            ->assertSee('voice-4.webm')
            ->assertDontSee('Never answered?')
            ->assertSee('See all 4');
    }
}
