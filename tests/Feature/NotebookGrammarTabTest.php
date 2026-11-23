<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GrammarShelf;
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

    /**
     * @param  list<array<string, mixed>>  $moreBlocks
     */
    private function missionWithLesson(string $code, string $title, string $focus, array $moreBlocks = []): Mission
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
                            'blocks' => [['type' => 'rule', 'text' => 'He <strong>works</strong> every day.', 'fa' => 'با he یک s اضافه می‌شود.'], ...$moreBlocks],
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

    public function test_an_open_lesson_lists_its_examples_with_the_persian_line_kept_apart_from_the_english(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);
        $mission = $this->missionWithLesson('M01', 'My Daily Life', 'Present Simple + Adverbs of Frequency', [
            ['type' => 'do_dont', 'items' => [['wrong' => 'He go to work.', 'right' => 'He goes to work.', 'fa' => 'بعد از he فعل s می‌گیرد.']]],
            ['type' => 'examples', 'groups' => [['label' => 'Examples', 'items' => ['She works late.', ['text' => 'I\'ve lost my phone.', 'fa' => 'گوشی‌ام را گم کرده‌ام.']]]]],
        ]);
        MissionRun::findOrStart($learner, $mission);

        Livewire::test('notebook.index')
            ->assertSee('He go to work.')
            ->assertSee('He goes to work.')
            ->assertSee('She works late.')
            ->assertSee('I\'ve lost my phone.')
            ->assertSee('<bdi dir="ltr">he</bdi> یک <bdi dir="ltr">s</bdi>', false)
            ->assertSee('گوشی‌ام را گم کرده‌ام.');
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

    public function test_a_mission_cards_grammar_line_uses_the_seeded_name_and_the_roadmap_for_unbuilt_missions(): void
    {
        $this->missionWithLesson('M01', 'My Daily Life', 'Present Simple + Adverbs of Frequency');

        $built = app(GrammarShelf::class)->nameFor('M01');
        $planned = app(GrammarShelf::class)->nameFor('M12');

        $this->assertSame('Present Simple + Adverbs of Frequency', $built['focus']);
        $this->assertNotNull($built['focusFa']);
        $this->assertSame('Reported Speech (basic)', $planned['focus']);
        $this->assertNull(app(GrammarShelf::class)->nameFor('M99'));
    }

    public function test_the_missions_page_hero_and_the_mission_banner_show_the_grammar_line(): void
    {
        $this->missionWithLesson('M01', 'My Daily Life', 'Present Simple + Adverbs of Frequency');

        $this->blade('<x-mission-banner mission-code="M01" title="My Daily Life" grammar />')
            ->assertSee('Grammar · Present Simple + Adverbs of Frequency');
        $this->blade('<x-mission-banner mission-code="M01" title="My Daily Life" />')
            ->assertDontSee('Grammar ·');
        $this->blade('<x-mission-grid-hero :mission="$mission" />', ['mission' => ['code' => 'M01', 'title' => 'My Daily Life', 'coverUrl' => null, 'url' => null, 'days' => []]])
            ->assertSee('Grammar · Present Simple + Adverbs of Frequency');
    }
}
