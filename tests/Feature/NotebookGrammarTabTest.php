<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Notebook's Grammar tab: every mission's grammar point in course
 * order; the lesson's rules open for a mission the learner has started,
 * the rest greyed with only their name.
 */
class NotebookGrammarTabTest extends TestCase
{
    use RefreshDatabase;

    private function missionWithLesson(string $code, string $title, string $focus): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => $title,
            'module' => 'Me',
            'outcome' => 'x',
            'phases' => [[
                'steps' => [[
                    'key' => 'grammar_in_context',
                    'focus' => $focus,
                    'lesson' => [
                        'intro' => 'Two small things.',
                        'sections' => [[
                            'heading' => 'A · The -s with he / she',
                            'blocks' => [['type' => 'rule', 'text' => 'He <strong>works</strong> every day.', 'fa' => 'با he یک s اضافه می‌شود.']],
                        ]],
                    ],
                ]],
            ]],
        ]);
    }

    public function test_grammar_is_a_tab_and_lists_all_24_grammar_points_in_course_order(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test('notebook.index')->assertSeeInOrder(['Words', 'Mistakes', 'Grammar']);

        $shelf = $component->instance()->grammarShelf;
        $this->assertCount(Mission::TOTAL_ROADMAP_MISSIONS, $shelf);
        $this->assertSame('M01', $shelf[0]['code']);
        $this->assertSame('M24', $shelf[23]['code']);
        $this->assertNotNull($shelf[0]['focusFa']);
    }

    public function test_a_started_mission_opens_its_rules_and_the_others_stay_closed(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $first = $this->missionWithLesson('M01', 'My Daily Life', 'Present Simple + Adverbs of Frequency');
        $this->missionWithLesson('M02', 'People I Know', 'Present Simple vs Present Continuous');
        MissionRun::findOrStart($learner, $first);

        $shelf = collect(Livewire::test('notebook.index')
            ->assertSee('He <strong>works</strong> every day.', false)
            ->assertSee('Present Simple + Adverbs of Frequency')
            ->instance()->grammarShelf)->keyBy('code');

        $this->assertTrue($shelf['M01']['open']);
        $this->assertTrue($shelf['M01']['current']);
        $this->assertFalse($shelf['M01']['done']);
        $this->assertFalse($shelf['M02']['open']);
        $this->assertSame([], $shelf['M02']['sections']);
    }

    public function test_a_finished_mission_is_done_and_not_current(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mission = $this->missionWithLesson('M01', 'My Daily Life', 'Present Simple + Adverbs of Frequency');
        MissionRun::findOrStart($learner, $mission)->update(['status' => MissionRun::STATUS_COMPLETE]);

        $first = Livewire::test('notebook.index')->instance()->grammarShelf[0];

        $this->assertTrue($first['open']);
        $this->assertTrue($first['done']);
        $this->assertFalse($first['current']);
    }
}
