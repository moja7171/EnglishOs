<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabProgress;
use App\Models\VocabText;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use App\Services\VocabCourse;
use Database\Seeders\VocabSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Courses: standalone studies outside the 24 missions (Vocab in Use first).
 * The hub, the course's own map of 90 units, the unit's three steps, and the
 * rule that the course's review cards never reach the Review tab.
 */
class CoursesTest extends TestCase
{
    use RefreshDatabase;

    private function readyUnit(int $number = 16): VocabUnit
    {
        $unit = VocabUnit::factory()->create(['number' => $number]);
        $text = VocabText::factory()->for($unit, 'unit')->create();
        VocabItem::factory()->for($text, 'text')->create(['position' => 1, 'phrase' => 'wake up', 'start' => 10, 'end' => 17]);
        VocabItem::factory()->for($text, 'text')->create(['position' => 2, 'phrase' => 'get up', 'start' => 38, 'end' => 44, 'sentence_index' => 1, 'gloss' => 'get out of bed']);

        return $unit;
    }

    public function test_the_seeder_loads_all_ninety_units_and_can_run_again(): void
    {
        $this->seed(VocabSeeder::class);
        $this->seed(VocabSeeder::class);

        $this->assertSame(90, VocabUnit::count());
        $this->assertSame([5, 94], [VocabUnit::min('number'), VocabUnit::max('number')]);
        $this->assertSame(16, VocabUnit::distinct()->count('book_section'));
    }

    public function test_home_has_one_courses_row_that_opens_the_courses_page(): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)->get(route('home'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('courses.index').'"')
            ->assertSee('Vocab in Use');
    }

    public function test_the_courses_page_shows_a_card_with_progress_and_due_cards(): void
    {
        $learner = User::factory()->create();
        $unit = $this->readyUnit();
        VocabUnitRun::factory()->for($learner)->for($unit, 'unit')->create();
        VocabProgress::factory()->for($learner)->for($unit->texts()->first(), 'text')->create();

        $this->actingAs($learner)->get(route('courses.index'))
            ->assertOk()
            ->assertSee('Vocab in Use')
            ->assertSee('1 of 1 units opened')
            ->assertSee('1 card due')
            ->assertSeeHtml('href="'.route('courses.vocab').'"');
    }

    public function test_due_course_cards_never_count_in_the_review_tab(): void
    {
        $learner = User::factory()->create();
        VocabProgress::factory()->for($learner)->for($this->readyUnit()->texts()->first(), 'text')->create();

        $this->assertSame(1, app(VocabCourse::class)->dueCards($learner));
        $this->assertSame(0, $learner->dailyReviewCount());
    }

    public function test_the_course_map_links_ready_units_and_dims_the_rest(): void
    {
        $learner = User::factory()->create();
        $ready = $this->readyUnit(16);
        VocabUnit::factory()->create(['number' => 17, 'title' => 'Not built yet']);

        $this->actingAs($learner)->get(route('courses.vocab'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('courses.vocab.unit', $ready->number).'"')
            ->assertDontSeeHtml('href="'.route('courses.vocab.unit', 17).'"')
            ->assertSee('Not built yet');
    }

    public function test_a_unit_without_texts_is_not_found(): void
    {
        $unit = VocabUnit::factory()->create(['number' => 20]);

        $this->actingAs(User::factory()->create())
            ->get(route('courses.vocab.unit', $unit->number))
            ->assertNotFound();
    }

    public function test_opening_a_unit_starts_its_run_and_shows_the_book_text_on_the_read_step(): void
    {
        $learner = User::factory()->create();
        $unit = $this->readyUnit();

        $this->actingAs($learner)->get(route('courses.vocab.unit', [$unit->number, 'read']))
            ->assertOk()
            ->assertSee('Step 2 of 3')
            ->assertSee('about 7.30 am.')
            ->assertSeeHtml('>wake up</button>');

        $this->assertSame(1, VocabUnitRun::where('user_id', $learner->id)->count());
    }

    public function test_listening_to_every_text_with_audio_opens_the_next_step(): void
    {
        config(['media.base_url' => 'https://dl.example.test/englishos']);
        $learner = User::factory()->create();
        $unit = $this->readyUnit();
        $this->actingAs($learner);

        $this->get(route('courses.vocab.unit', $unit->number))
            ->assertSee('Listen to 90% to continue')
            ->assertSeeHtml('https://dl.example.test/englishos/vocab/U_016.A.mp3');

        Livewire::test('courses.vocab-unit', ['unitId' => $unit->id])
            ->assertSee('Listen to 90% to continue')
            ->call('markListened', 'A')
            ->assertSet('listened', ['A'])
            ->assertDontSee('Listen to 90% to continue');

        $run = VocabUnitRun::first();
        $this->assertSame(['A'], $run->listened_letters);
        $this->assertNotNull($run->listen_done_at);
    }

    public function test_saving_two_words_of_one_sentence_makes_one_card_and_removing_both_deletes_it(): void
    {
        $learner = User::factory()->create();
        $unit = $this->readyUnit();
        [$first, $second] = $unit->texts()->first()->items;
        $second->update(['sentence_index' => 0]);
        $this->actingAs($learner);

        $component = Livewire::test('courses.vocab-unit', ['unitId' => $unit->id, 'step' => 'read'])
            ->call('toggleSaved', $first->id)
            ->call('toggleSaved', $second->id)
            ->assertSet('saved', [$first->id, $second->id]);

        $card = VocabProgress::sole();
        $this->assertSame([$first->id, $second->id], $card->saved_item_ids);
        $this->assertTrue($card->next_review_at->isFuture());

        $component->call('toggleSaved', $first->id);
        $this->assertSame([$second->id], VocabProgress::sole()->saved_item_ids);

        $component->call('toggleSaved', $second->id);
        $this->assertSame(0, VocabProgress::count());
    }

    public function test_a_word_of_another_unit_cannot_be_saved(): void
    {
        $learner = User::factory()->create();
        $mine = $this->readyUnit(16);
        $other = $this->readyUnit(17)->texts()->first()->items->first();

        $component = Livewire::actingAs($learner)->test('courses.vocab-unit', ['unitId' => $mine->id, 'step' => 'read']);

        try {
            $component->call('toggleSaved', $other->id);
            $this->fail('A word of another unit was accepted.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, VocabProgress::count());
        }
    }

    public function test_the_words_step_lists_the_saved_sentences_and_finishing_marks_the_unit_done(): void
    {
        $learner = User::factory()->create();
        $unit = $this->readyUnit();
        $item = $unit->texts()->first()->items->first();
        app(VocabCourse::class)->saveForReview($learner, $item);

        Livewire::actingAs($learner)->test('courses.vocab-unit', ['unitId' => $unit->id, 'step' => 'words'])
            ->assertSee('wake up')
            ->assertSee('stop sleeping')
            ->call('finish')
            ->assertRedirect(route('courses.vocab'));

        $run = VocabUnitRun::sole();
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->read_done_at);
    }

    public function test_the_words_step_without_saved_words_points_back_to_read(): void
    {
        $unit = $this->readyUnit();

        Livewire::actingAs(User::factory()->create())->test('courses.vocab-unit', ['unitId' => $unit->id, 'step' => 'words'])
            ->assertSee('No words saved yet.');
    }

    public function test_persian_shows_on_the_read_step_only_when_the_course_has_it(): void
    {
        $learner = User::factory()->create();
        $unit = $this->readyUnit();
        $text = $unit->texts()->first();

        $this->actingAs($learner)->get(route('courses.vocab.unit', [$unit->number, 'read']))
            ->assertDontSee('فا');

        $text->update(['sentences' => collect($text->sentences)->map(fn (array $sentence): array => $sentence + ['fa' => 'من معمولاً ساعت هفت و نیم بیدار می‌شوم.'])->all()]);
        $text->items()->where('phrase', 'wake up')->update(['phrase_fa' => 'بیدار شدن']);

        $this->get(route('courses.vocab.unit', [$unit->number, 'read']))
            ->assertSee('فا')
            ->assertSee('من معمولاً ساعت هفت و نیم بیدار می‌شوم.')
            ->assertSee('بیدار شدن');
    }
}
