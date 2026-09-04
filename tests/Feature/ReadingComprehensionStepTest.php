<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Services\GeminiClient;
use App\Services\PexelsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReadingComprehensionStepTest extends TestCase
{
    use RefreshDatabase;

    private function makeRun(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                [
                    'phase' => 'practice',
                    'steps' => [
                        [
                            'key' => 'reading_comprehension',
                            'hook' => 'Meet Aisha — her morning looks a lot like yours.',
                            'passage_title' => 'Meet Aisha',
                            'passage' => 'Aisha lives in Manchester and works at a hospital. She wakes up at six.',
                            'topic_summary' => "A short profile of Aisha's daily routine.",
                            'comprehension_check' => [
                                ['statement' => 'Aisha works at a hospital.', 'correct' => true],
                                ['statement' => 'Aisha wakes up at nine.', 'correct' => false],
                            ],
                            'questions' => [
                                "What is Aisha's morning usually like on a weekday?",
                                "What is different about Aisha's Sunday, compared to a normal weekday?",
                            ],
                        ],
                        ['key' => 'writing'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    private function makeRunWithImageQuery(): MissionRun
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage_title' => 'Meet Aisha',
                    'passage' => 'Aisha lives in Manchester.',
                    'image_query' => 'young woman morning portrait smiling',
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);

        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_it_shows_the_passage(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('Meet Aisha')
            ->assertSee('Aisha lives in Manchester and works at a hospital.');
    }

    public function test_the_page_is_split_into_2_sub_steps(): void
    {
        $run = $this->makeRun();

        $html = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])->html();

        $this->assertStringContainsString('activeSubstep === 0', $html);
        $this->assertStringContainsString('activeSubstep === 1', $html);
        $this->assertStringContainsString('Part', $html);
    }

    public function test_continue_is_hidden_until_both_answers_are_filled(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSeeHtml('x-on:click="$wire.save()"')
            ->set('answers.0', 'She wakes up early.')
            ->assertDontSeeHtml('x-on:click="$wire.save()"')
            ->set('answers.1', 'She sleeps in.')
            ->assertSeeHtml('x-on:click="$wire.save()"');
    }

    public function test_a_passage_with_an_image_query_shows_a_header_image(): void
    {
        $run = $this->makeRunWithImageQuery();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')
            ->with('M01-reading', 'young woman morning portrait smiling')
            ->once()
            ->andReturn('http://localhost/storage/vocabulary-images/m01-reading.jpg'));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('http://localhost/storage/vocabulary-images/m01-reading.jpg');
    }

    public function test_a_passage_without_an_image_query_shows_no_header_image(): void
    {
        $run = $this->makeRun();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldNotReceive('imageUrlFor'));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSeeHtml('<img');
    }

    public function test_a_failed_image_fetch_never_breaks_the_step(): void
    {
        $run = $this->makeRunWithImageQuery();

        $this->mock(PexelsClient::class, fn ($mock) => $mock->shouldReceive('imageUrlFor')->once()->andReturn(null));

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertOk()
            ->assertDontSeeHtml('<img');
    }

    public function test_it_shows_an_ungraded_true_false_warm_up(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSee('Aisha works at a hospital.')
            ->assertSee('True')
            ->assertSee('False');
    }

    public function test_clicking_check_on_an_empty_answer_shows_an_error(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldNotReceive('chat');
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->call('checkOne', 0)
            ->assertSet('checkErrors.0', 'Write something first.')
            ->assertSee('Write something first.');
    }

    public function test_both_questions_must_be_answered_before_continuing(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She wakes up early and never skips breakfast.')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_major_ai_verdict_blocks_continue(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'major', 'hint' => 'That is off-topic.']))
                ->ordered();
            $mock->shouldReceive('chat')
                ->once()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']))
                ->ordered();
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'I like pizza on Fridays.')
            ->set('answers.1', 'On Sunday she sleeps in until ten.')
            ->call('save')
            ->assertHasErrors(['answers']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_a_valid_submission_stores_evidence_and_advances(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')
                ->twice()
                ->andReturn(json_encode(['severity' => 'none', 'hint' => '']));
        });

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'She wakes up early and never skips breakfast.')
            ->set('answers.1', 'On Sunday she sleeps in and does not set an alarm.')
            ->call('save');

        $this->assertDatabaseCount('evidences', 1);
        $this->assertDatabaseHas('evidences', ['mission_run_id' => $run->id, 'phase' => 'reading_comprehension']);

        $evidence = Evidence::where('phase', 'reading_comprehension')->first();
        $content = json_decode($evidence->content_ref, true);
        $this->assertSame('She wakes up early and never skips breakfast.', $content['answers'][0]);

        $this->assertSame('writing', $run->fresh()->currentStepKey());
    }

    public function test_three_failed_checks_offer_to_reveal_the_correction(): void
    {
        $run = $this->makeRun();

        $this->mock(GeminiClient::class, function ($mock) {
            $mock->shouldReceive('chat')->times(3)->andReturn(json_encode(['severity' => 'major', 'hint' => 'Try again.']));
        });

        $component = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->set('answers.0', 'pizza');

        $component->call('checkOne', 0);
        $component->call('checkOne', 0);
        $component->call('checkOne', 0)
            ->assertSet('offerReveal.0', true);
    }

    public function test_a_seeded_difficulty_tag_is_threaded_through_to_the_comprehension_cards(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha lives in Manchester.',
                    'comprehension_check' => [
                        ['statement' => 'Aisha works at a hospital.', 'correct' => true, 'difficulty' => 'easy'],
                        ['statement' => 'She goes shopping on Saturdays.', 'correct' => true, 'difficulty' => 'hard'],
                    ],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        $cards = Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->instance()
            ->comprehensionCards();

        $this->assertSame('easy', $cards[0]['difficulty']);
        $this->assertSame('hard', $cards[1]['difficulty']);
    }

    public function test_reused_and_new_phrases_are_highlighted_with_distinct_tooltips(): void
    {
        $learner = User::factory()->create();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [[
                'phase' => 'practice',
                'steps' => [[
                    'key' => 'reading_comprehension',
                    'passage' => 'Aisha wakes up at six and feels exhausted after a long shift.',
                    'highlighted_phrases' => [
                        ['phrase' => 'wakes up', 'type' => 'reused'],
                        ['phrase' => 'exhausted', 'type' => 'new', 'definition' => 'very tired'],
                    ],
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);
        $run = MissionRun::findOrStart($learner, $mission);

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertSeeHtml('<mark')
            ->assertSeeHtml('title="این کلمه رو توی Vocabulary Builder دیدی"')
            ->assertSeeHtml('title="very tired"')
            // The rest of the passage around the highlights must still render, unescaped-HTML-safe.
            ->assertSee('Aisha')
            ->assertSee('after a long shift');
    }

    public function test_a_passage_without_highlighted_phrases_renders_plainly(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run])
            ->assertDontSeeHtml('<mark');
    }

    public function test_read_only_mode_reloads_the_saved_answers_and_hides_the_warm_up(): void
    {
        $run = $this->makeRun();

        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'reading_comprehension',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['answers' => [
                'She wakes up early and never skips breakfast.',
                'On Sunday she sleeps in and does not set an alarm.',
            ]]),
        ]);

        Livewire::test('missions.steps.reading-comprehension', ['run' => $run, 'readOnly' => true])
            ->assertSet('answers.0', 'She wakes up early and never skips breakfast.')
            ->assertSet('answers.1', 'On Sunday she sleeps in and does not set an alarm.')
            ->assertDontSee('Quick check')
            ->assertDontSeeHtml('x-draft');
    }
}
