<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Where the tap-a-word dictionary is wired in: the sheet itself in the
 * layout, and the texts a learner can tap. The tap and the sheet are
 * browser behavior (resources/js/app.js) — these only prove the markup
 * that switches them on is there.
 */
class DictionarySheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_learner_gets_the_sheet_and_a_csrf_token_on_every_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertSee('dictionarySheet(', false)
            ->assertSee(addcslashes(route('dictionary.lookup'), '/'), false)
            ->assertSee(addcslashes(route('vocabulary.store'), '/'), false)
            ->assertSee('name="csrf-token"', false)
            ->assertSee('keydown.escape.window.capture', false)
            ->assertSee('x-back-close=', false)
            ->assertSee('Other meanings (', false)
            ->assertSee('pick(chip.index)', false)
            ->assertSee('usePhrase()', false)
            ->assertSee(addcslashes(route('dictionary.persian'), '/'), false);
    }

    public function test_a_signed_out_visitor_does_not_get_the_sheet(): void
    {
        $this->get(route('login'))
            ->assertDontSee('dictionarySheet(', false);
    }

    public function test_the_reading_passage_can_be_tapped_word_by_word(): void
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
                    'questions' => ['What is Aisha like?'],
                ]],
            ]],
        ]);
        $this->actingAs($learner);

        Livewire::test('missions.steps.reading-comprehension', ['run' => MissionRun::findOrStart($learner, $mission)])
            ->assertSeeHtml('<p x-lookup class="text-base leading-loose')
            ->assertSee('Aisha lives in Manchester.');
    }

    public function test_the_review_card_shows_the_persian_meaning_beside_the_english_one(): void
    {
        $learner = User::factory()->create();
        $learner->vocabularyWords()->create([
            'word' => 'commute',
            'meaning' => 'to travel to work',
            'meaning_fa' => 'رفت‌وآمد',
            'next_review_at' => now()->subHour(),
        ]);
        $this->actingAs($learner);

        Livewire::test('vocabulary.index')
            ->assertSee('to travel to work')
            ->assertSee('رفت‌وآمد');
    }
}
