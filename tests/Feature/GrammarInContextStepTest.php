<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GrammarInContextStepTest extends TestCase
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
                    'phase' => 'build',
                    'steps' => [
                        [
                            'key' => 'grammar_in_context',
                            'focus' => 'Present Simple + Adverbs of Frequency',
                            'frequency_starters' => ['I usually', 'I often', 'I sometimes', 'I rarely'],
                            'quick_check' => [
                                ['wrong' => 'She go to work.', 'correct' => 'She goes to work.'],
                                ['wrong' => 'He wake up late.', 'correct' => 'He wakes up late.'],
                            ],
                        ],
                        ['key' => 'activation'],
                    ],
                ],
            ],
        ]);

        return MissionRun::findOrStart($learner, $mission);
    }

    public function test_requires_three_sentences_and_all_corrections(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->call('save')
            ->assertHasErrors(['frequencySentences']);

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('corrections.0', 'She goes to work.')
            ->call('save')
            ->assertHasErrors(['corrections']);

        $this->assertDatabaseCount('evidences', 0);
    }

    public function test_valid_submission_records_evidence_and_advances_the_run(): void
    {
        $run = $this->makeRun();

        Livewire::test('missions.steps.grammar-in-context', ['run' => $run])
            ->set('frequencySentences.0', 'I usually wake up at 7.')
            ->set('frequencySentences.1', 'I often cook dinner.')
            ->set('frequencySentences.2', 'I sometimes exercise.')
            ->set('corrections.0', 'She goes to work.')
            ->set('corrections.1', 'He wakes up late.')
            ->call('save')
            ->assertRedirect(route('missions.show', $run->mission));

        $evidence = Evidence::where('phase', 'grammar_in_context')->first();
        $content = json_decode($evidence->content_ref, true);

        $this->assertCount(3, $content['frequency_sentences']);
        $this->assertSame('She goes to work.', $content['corrections'][0]['my_correction']);

        $this->assertSame('activation', $run->fresh()->currentStepKey());
    }
}
