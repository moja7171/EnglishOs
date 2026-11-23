<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabText;
use App\Models\VocabularyWord;
use App\Models\VocabUnit;
use App\Services\VocabCourse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vocab in Use words are reviewed in the Review tab on their own path, with
 * the same cards as the lesson Words; and today's review ring slice needs
 * every path that had work to be finished.
 */
class VocabReviewPathTest extends TestCase
{
    use RefreshDatabase;

    private function addedWord(User $learner, string $phrase = 'comb your hair'): VocabularyWord
    {
        $unit = VocabUnit::factory()->create();
        $text = VocabText::factory()->for($unit, 'unit')->create();
        $item = VocabItem::factory()->for($text, 'text')->create(['phrase' => $phrase, 'start' => null, 'end' => null, 'phrase_fa' => 'موهایت را شانه کردن']);
        app(VocabCourse::class)->addWords($learner, $unit, [$item->id]);

        return VocabularyWord::where('vocab_item_id', $item->id)->sole();
    }

    private function lessonWord(User $learner, string $word = 'wake up', array $state = []): VocabularyWord
    {
        return $learner->vocabularyWords()->create($state + ['word' => $word, 'meaning' => 'stop sleeping', 'meaning_fa' => 'بیدار شدن', 'next_review_at' => now()->subHour()]);
    }

    public function test_added_words_belong_to_the_vocab_path_and_lesson_words_to_the_words_path(): void
    {
        $learner = User::factory()->create();
        $this->addedWord($learner);
        $this->lessonWord($learner);

        $this->assertSame(1, $learner->dailyReviewCount('vocab'));
        $this->assertSame(1, $learner->dailyReviewCount('words'));
        $this->assertSame(2, $learner->dailyReviewCount());
        $this->assertSame(['word'], $learner->dailyReviewItems('vocab')->pluck('type')->all());
        $this->assertSame($learner->vocabularyWords()->fromVocabCourse()->value('id'), $learner->dailyReviewItems('vocab')->sole()['id']);
    }

    public function test_the_review_page_has_a_vocab_card_and_the_vocab_path_shows_the_added_word(): void
    {
        $learner = User::factory()->create();
        $this->addedWord($learner);
        $this->actingAs($learner);

        $this->get(route('review.index'))
            ->assertOk()
            ->assertSee('Vocab in Use')
            ->assertSee('1 left today');

        $this->get(route('review.track', 'vocab'))
            ->assertOk()
            ->assertSee('comb your hair')
            ->assertSee('Unit', false);
    }

    public function test_a_phrase_reviewed_today_as_a_lesson_word_is_not_asked_again_as_a_vocab_word(): void
    {
        $learner = User::factory()->create();
        $this->addedWord($learner, 'wake up');
        $this->lessonWord($learner, 'Wake Up', ['last_reviewed_at' => now(), 'next_review_at' => now()->addDays(3)]);

        $this->assertSame(0, $learner->dailyReviewCount('vocab'));
    }

    public function test_the_ring_slice_for_review_needs_every_path_with_work_to_be_finished(): void
    {
        $learner = User::factory()->create();
        $vocab = $this->addedWord($learner);
        $lesson = $this->lessonWord($learner);

        $lesson->review(4);
        $this->assertFalse(DayActivity::where('learner_id', $learner->id)->where('activity', 'review')->exists(), 'the vocab path is still waiting');

        $vocab->review(4);
        $this->assertTrue(DayActivity::where('learner_id', $learner->id)->where('activity', 'review')->exists());
    }

    public function test_a_path_with_nothing_due_does_not_hold_the_review_slice_back(): void
    {
        $learner = User::factory()->create();
        $this->lessonWord($learner)->review(4);

        $this->assertTrue(DayActivity::where('learner_id', $learner->id)->where('activity', 'review')->exists());
    }

    public function test_the_mistakes_path_gives_at_least_ten_a_day_and_vocab_twelve(): void
    {
        $this->assertSame(10, User::DAILY_TALK_LIMIT);
        $this->assertSame(12, User::DAILY_VOCAB_LIMIT);
    }
}
