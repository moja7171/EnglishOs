<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabText;
use App\Models\VocabularyWord;
use App\Models\VocabUnit;
use App\Models\VocabUnitRun;
use App\Services\VocabCourse;
use Database\Seeders\VocabSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Courses: standalone studies outside the 24 missions (Vocab in Use first).
 * The hub, the lesson list, a lesson's sections and its word list, and the
 * words a learner adds from it.
 */
class CoursesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A lesson with one section of text and three new words ("wake up" sits in the text).
     */
    private function lesson(int $number = 16): VocabUnit
    {
        $unit = VocabUnit::factory()->create(['number' => $number]);
        $text = VocabText::factory()->for($unit, 'unit')->create(['audio_file' => 'U_016.A.mp3']);
        VocabItem::factory()->for($text, 'text')->create(['position' => 1, 'phrase' => 'wake up', 'start' => 10, 'end' => 17, 'phrase_fa' => 'بیدار شدن']);
        VocabItem::factory()->for($text, 'text')->create(['position' => 2, 'phrase' => 'get up', 'start' => 38, 'end' => 44, 'sentence_index' => 1, 'gloss' => 'get out of bed', 'phrase_fa' => 'بلند شدن']);
        VocabItem::factory()->for($text, 'text')->create(['position' => 3, 'phrase' => 'comb your hair', 'start' => null, 'end' => null, 'sentence_index' => null, 'ipa' => 'kəʊm jɔː heə', 'phrase_fa' => 'موهایت را شانه کردن']);

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
        $this->actingAs(User::factory()->create())->get(route('home'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('courses.index').'"')
            ->assertSee('Vocab in Use');
    }

    public function test_the_courses_page_shows_a_card_with_progress_and_due_words(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();
        VocabUnitRun::factory()->for($learner)->for($unit, 'unit')->create();
        app(VocabCourse::class)->addWords($learner, $unit, [$unit->texts->first()->items->first()->id]);

        $this->actingAs($learner)->get(route('courses.index'))
            ->assertOk()
            ->assertSee('Vocab in Use')
            ->assertSee('1 of 1 units opened')
            ->assertSee('1 card due')
            ->assertSeeHtml('href="'.route('courses.vocab').'"')
            ->assertSeeHtml('images/courses/vocab.jpg')
            ->assertSeeHtml('images/extra/01.jpg');
    }

    public function test_the_lesson_list_shows_progress_and_dims_lessons_that_are_not_built(): void
    {
        $learner = User::factory()->create();
        $ready = $this->lesson(16);
        VocabUnit::factory()->create(['number' => 17, 'title' => 'Not built yet']);
        app(VocabCourse::class)->addWords($learner, $ready, [$ready->texts->first()->items->first()->id]);

        $this->actingAs($learner)->get(route('courses.vocab'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('courses.vocab.unit', 16).'"')
            ->assertDontSeeHtml('href="'.route('courses.vocab.unit', 17).'"')
            ->assertSee('Not built yet')
            ->assertSee('1 / 3')
            ->assertSee('1 word to review')
            ->assertSeeHtml('href="'.route('review.track', 'vocab').'"')
            ->assertSeeHtml('id="vocab-search"');
    }

    public function test_a_lesson_without_texts_is_not_found(): void
    {
        $unit = VocabUnit::factory()->create(['number' => 20]);

        $this->actingAs(User::factory()->create())
            ->get(route('courses.vocab.unit', $unit->number))
            ->assertNotFound();
    }

    public function test_opening_a_lesson_starts_its_run_and_shows_the_section_text_with_its_words_underlined(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();

        $this->actingAs($learner)->get(route('courses.vocab.unit', $unit->number))
            ->assertOk()
            ->assertSee('Section A of 1')
            ->assertSee('about 7.30 am.')
            ->assertSeeHtml('>wake up</button>')
            ->assertSee('Words ›');

        $this->assertSame(1, VocabUnitRun::where('user_id', $learner->id)->count());
    }

    public function test_the_words_page_lists_every_new_word_in_one_list_with_its_persian(): void
    {
        $unit = $this->lesson();

        $this->actingAs(User::factory()->create())->get(route('courses.vocab.unit', [$unit->number, 'words']))
            ->assertOk()
            ->assertSee('3 new words')
            ->assertSee('comb your hair')
            ->assertSee('/kəʊm jɔː heə/', false)
            ->assertSee('موهایت را شانه کردن')
            ->assertSee('Select all');
    }

    public function test_adding_words_makes_review_cards_finishes_the_lesson_and_earns_the_days_slice(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();
        [$wake, $get] = $unit->texts->first()->items;

        Livewire::actingAs($learner)->test('courses.vocab-unit', ['unitId' => $unit->id, 'part' => 'words'])
            ->call('add', [$wake->id, $get->id], [])
            ->assertRedirect(route('courses.vocab'));

        $word = VocabularyWord::where('learner_id', $learner->id)->where('vocab_item_id', $wake->id)->sole();
        $this->assertSame(VocabularyWord::SOURCE_VOCAB, $word->source);
        $this->assertSame('wake up', $word->word);
        $this->assertSame('بیدار شدن', $word->meaning_fa);
        $this->assertTrue($word->next_review_at->lte(now()));
        $this->assertSame(2, $learner->vocabularyWords()->fromVocabCourse()->count());
        $this->assertNotNull(VocabUnitRun::sole()->finished_at);
        $this->assertTrue(DayActivity::where('learner_id', $learner->id)->where('activity', 'vocab')->exists());
        $this->assertSame('2 words added to Review · Vocab in Use', session('vocab-added'));
    }

    public function test_finishing_without_choosing_still_finishes_the_lesson_and_counts_for_the_day(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();

        Livewire::actingAs($learner)->test('courses.vocab-unit', ['unitId' => $unit->id, 'part' => 'words'])
            ->call('add', [], [])
            ->assertRedirect(route('courses.vocab'));

        $this->assertSame(0, VocabularyWord::count());
        $this->assertNotNull(VocabUnitRun::sole()->finished_at);
        $this->assertTrue(DayActivity::where('learner_id', $learner->id)->where('activity', 'vocab')->exists());
    }

    public function test_a_word_added_twice_stays_one_card(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();
        $item = $unit->texts->first()->items->first();
        $course = app(VocabCourse::class);

        $this->assertSame(1, $course->addWords($learner, $unit, [$item->id]));
        $this->assertSame(0, $course->addWords($learner, $unit, [$item->id]));
        $this->assertSame(1, VocabularyWord::count());
    }

    public function test_a_word_already_in_the_learners_lesson_words_is_not_added_unless_asked(): void
    {
        $learner = User::factory()->create();
        $unit = $this->lesson();
        $item = $unit->texts->first()->items->first();
        $learner->vocabularyWords()->create(['word' => 'Wake Up', 'repetitions' => 5, 'interval_days' => 30, 'last_reviewed_at' => now()->subDay(), 'next_review_at' => now()->addMonth()]);
        $course = app(VocabCourse::class);

        $this->assertSame(['state' => 'in_words', 'strength' => 'Strong'], $course->statuses($learner, collect([$item]))[$item->id]);
        $this->assertSame(0, $course->addWords($learner, $unit, [$item->id]));
        $this->assertSame(1, VocabularyWord::count());

        $this->actingAs($learner)->get(route('courses.vocab.unit', [$unit->number, 'words']))
            ->assertSee('In your Words · Strong')
            ->assertSee('Add anyway')
            ->assertDontSee('In your Words · Learning');

        $this->assertSame(1, $course->addWords($learner, $unit, [$item->id], [$item->id]));
        $this->assertSame(2, VocabularyWord::count());
    }

    public function test_another_learners_words_do_not_count_for_this_lesson(): void
    {
        $unit = $this->lesson();
        $item = $unit->texts->first()->items->first();
        $other = User::factory()->create();
        app(VocabCourse::class)->addWords($other, $unit, [$item->id]);

        $this->assertSame('new', app(VocabCourse::class)->statuses(User::factory()->create(), collect([$item]))[$item->id]['state']);
    }

    public function test_the_section_sheet_gets_the_persian_and_the_pronunciation(): void
    {
        $unit = $this->lesson();

        $this->actingAs(User::factory()->create())->get(route('courses.vocab.unit', $unit->number))
            ->assertSee('بیدار شدن')
            ->assertSee('weɪk ʌp');
    }

    public function test_the_section_has_no_show_text_button_because_the_text_is_already_on_the_page(): void
    {
        $unit = $this->lesson();
        $text = $unit->texts->first();
        $text->update(['sentences' => collect($text->sentences)->map(fn (array $sentence): array => $sentence + ['fa' => 'من معمولاً بیدار می‌شوم.'])->all()]);

        $this->actingAs(User::factory()->create())->get(route('courses.vocab.unit', $unit->number))
            ->assertOk()
            ->assertDontSee('Show text')
            ->assertSee('ترجمه فارسی')
            ->assertSeeHtml('data-testid="vocab-section-fa"')
            ->assertSee('من معمولاً بیدار می‌شوم.');
    }
}
