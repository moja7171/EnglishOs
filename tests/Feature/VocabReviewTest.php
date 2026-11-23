<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VocabItem;
use App\Models\VocabProgress;
use App\Models\VocabText;
use App\Models\VocabUnit;
use App\Services\VocabCourse;
use App\Services\VocabReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Vocab in Use course's own review: due cards in a daily queue, the
 * four-step ladder, the lenient check and the 1-3-7-14 day schedule.
 */
class VocabReviewTest extends TestCase
{
    use RefreshDatabase;

    private const SENTENCE = 'I get up a few minutes later.';

    /**
     * A one-sentence text whose saved word is "get up" ("get out of bed"),
     * plus two other glossed words so the See step has meanings to pick from.
     *
     * @return array{0: VocabText, 1: VocabItem}
     */
    private function text(string $letter = 'A'): array
    {
        $text = VocabText::factory()->for(VocabUnit::factory(), 'unit')->create([
            'letter' => $letter,
            'body' => self::SENTENCE,
            'sentences' => [['text' => self::SENTENCE, 'start' => 0, 'end' => 29, 'audio_start' => 1.0, 'audio_end' => 3.0]],
        ]);
        $item = VocabItem::factory()->for($text, 'text')->create(['phrase' => 'get up', 'start' => 2, 'end' => 8, 'gloss' => 'get out of bed', 'sentence_index' => 0]);
        VocabItem::factory()->for($text, 'text')->create(['position' => 2, 'phrase' => 'snack', 'start' => 100, 'end' => 105, 'gloss' => 'food between meals']);
        VocabItem::factory()->for($text, 'text')->create(['position' => 3, 'phrase' => 'feed', 'start' => 110, 'end' => 114, 'gloss' => 'give food']);

        return [$text, $item];
    }

    private function card(User $user, VocabText $text, VocabItem $item, array $state = []): VocabProgress
    {
        return VocabProgress::factory()->for($user)->for($text, 'text')->create($state + [
            'sentence_index' => 0,
            'saved_item_ids' => [$item->id],
        ]);
    }

    public function test_the_first_reviews_come_after_1_3_7_and_14_days_then_sm2_takes_over(): void
    {
        [$text, $item] = $this->text();
        $card = $this->card(User::factory()->create(), $text, $item);
        $days = [];

        foreach (range(1, 5) as $ignored) {
            $card->grade(4);
            $days[] = $card->interval_days;
        }

        $this->assertSame([3, 7, 14], array_slice($days, 0, 3));
        $this->assertGreaterThan(14, $days[3]);
    }

    public function test_a_wrong_answer_steps_down_the_ladder_and_comes_back_tomorrow(): void
    {
        [$text, $item] = $this->text();
        $card = $this->card(User::factory()->create(), $text, $item, ['ladder_step' => 3, 'repetitions' => 2, 'interval_days' => 7]);

        $card->grade(1);

        $this->assertSame(2, $card->ladder_step);
        $this->assertSame(1, $card->interval_days);
        $this->assertSame(0, $card->repetitions);
        $this->assertSame(1, $card->lapses);
        $this->assertTrue($card->next_review_at->isTomorrow());
    }

    public function test_four_wrong_answers_in_a_row_make_a_sentence_tricky_and_a_right_one_clears_it(): void
    {
        [$text, $item] = $this->text();
        $card = $this->card(User::factory()->create(), $text, $item);

        foreach (range(1, 3) as $ignored) {
            $card->grade(1);
        }
        $this->assertFalse($card->isTricky());

        $card->grade(1);
        $this->assertTrue($card->isTricky());

        $card->grade(4);
        $this->assertFalse($card->isTricky());
    }

    public function test_the_ladder_tops_out_at_recall(): void
    {
        [$text, $item] = $this->text();
        $card = $this->card(User::factory()->create(), $text, $item);

        foreach (range(1, 6) as $ignored) {
            $card->grade(4);
        }

        $this->assertSame(VocabProgress::LADDER_STEPS, $card->ladder_step);
    }

    public function test_the_sentence_check_forgives_small_slips_but_not_a_changed_meaning(): void
    {
        $review = app(VocabReview::class);
        $expected = 'If Mum is late home from work, we just get a takeaway instead.';
        $required = ['takeaway'];

        $this->assertSame('right', $review->checkSentence('if mum is late home from work we just get a takeaway instead', $expected, $required)['verdict']);

        $missingA = $review->checkSentence('If Mum is late home from work, we just get takeaway instead.', $expected, $required);
        $this->assertSame('almost', $missingA['verdict']);
        $this->assertSame(['a'], $missingA['missing']);

        $this->assertSame('almost', $review->checkSentence('If Mum is late home from work, we just get a takeawy instead.', $expected, $required)['verdict']);
        $this->assertSame('wrong', $review->checkSentence('If Mum is late home from work, we just get a pizza instead.', $expected, $required)['verdict']);
        $this->assertSame('wrong', $review->checkSentence('Mum is late.', $expected, $required)['verdict']);
        $this->assertSame('wrong', $review->checkSentence('', $expected, $required)['verdict']);
    }

    public function test_the_gap_check_accepts_a_typo_in_a_long_word_only(): void
    {
        $review = app(VocabReview::class);

        $this->assertTrue($review->checkWord(' Takeaway ', 'takeaway'));
        $this->assertTrue($review->checkWord('takeawy', 'takeaway'));
        $this->assertFalse($review->checkWord('cat', 'bat'));
        $this->assertFalse($review->checkWord('', 'bat'));
    }

    public function test_a_long_sentence_is_put_in_order_in_two_parts(): void
    {
        $review = app(VocabReview::class);

        $this->assertCount(1, $review->orderParts('We just get a takeaway instead.'));

        $parts = $review->orderParts('In the evenings I go to bed about ten and usually go to sleep straight away.');
        $this->assertCount(2, $parts);
        $this->assertSame(16, count($parts[0]) + count($parts[1]));
    }

    public function test_a_card_whose_words_have_no_meaning_in_the_book_starts_at_the_gap_step(): void
    {
        [$text, $item] = $this->text();
        $item->update(['gloss' => null]);
        $card = $this->card(User::factory()->create(), $text, $item)->load('text.items');

        $this->assertSame(2, app(VocabReview::class)->stepFor($card));
    }

    public function test_the_see_step_marks_a_right_pick_and_good_moves_the_card_up_the_ladder(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();
        $card = $this->card($learner, $text, $item);

        $component = Livewire::actingAs($learner)->test('courses.vocab-review')
            ->assertSet('step', 1)
            ->assertSee('What does “get up” mean?');

        $right = array_search('get out of bed', $component->get('options'), true);
        $component->call('pick', $right)
            ->assertSet('verdict', 'right')
            ->call('grade', 'good')
            ->assertSee('Done');

        $card->refresh();
        $this->assertSame(2, $card->ladder_step);
        $this->assertSame(3, $card->interval_days);
    }

    public function test_the_gap_step_checks_the_typed_word_and_a_wrong_one_can_only_be_again(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();
        $card = $this->card($learner, $text, $item, ['ladder_step' => 2]);

        Livewire::actingAs($learner)->test('courses.vocab-review')
            ->assertSet('step', 2)
            ->set('typed', 'wake up')
            ->call('submitTyped')
            ->assertSet('verdict', 'wrong')
            ->call('grade', 'easy');

        $card->refresh();
        $this->assertSame(1, $card->ladder_step);
        $this->assertSame(1, $card->interval_days);
        $this->assertSame(1, $card->misses_in_row);
    }

    public function test_the_order_step_wants_the_book_order_and_the_recall_step_the_sentence(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();
        $card = $this->card($learner, $text, $item, ['ladder_step' => 3]);

        $order = Livewire::actingAs($learner)->test('courses.vocab-review')->assertSet('step', 3);
        $pool = $order->get('pool');

        foreach (explode(' ', self::SENTENCE) as $word) {
            $order->call('addWord', array_search($word, $pool, true));
        }
        $order->call('checkOrder')->assertSet('verdict', 'right');

        $card->update(['ladder_step' => 4]);

        Livewire::actingAs($learner)->test('courses.vocab-review')
            ->assertSet('step', 4)
            ->set('typed', 'I get up a few minuts later')
            ->call('submitTyped')
            ->assertSet('verdict', 'almost')
            ->call('grade', 'good');

        $this->assertSame(4, $card->fresh()->ladder_step);
    }

    public function test_a_day_lets_forty_cards_in_and_twenty_more_come_on_request(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();

        foreach (range(1, 70) as $sentence) {
            $this->card($learner, $text, $item, ['sentence_index' => $sentence]);
        }

        $component = Livewire::actingAs($learner)->test('courses.vocab-review');
        $this->assertCount(VocabReview::DAILY_CAP, $component->get('queue'));
        $this->assertSame(30, app(VocabCourse::class)->dueCards($learner) - VocabReview::DAILY_CAP);

        $component->set('index', VocabReview::DAILY_CAP)->call('more');
        $this->assertCount(VocabReview::DAILY_CAP + VocabReview::MORE, $component->get('queue'));
    }

    public function test_cards_that_are_not_due_or_belong_to_someone_else_are_not_asked(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();
        $this->card($learner, $text, $item, ['next_review_at' => now()->addDay()]);
        $this->card(User::factory()->create(), $text, $item);

        Livewire::actingAs($learner)->test('courses.vocab-review')
            ->assertSet('queue', [])
            ->assertSee('Nothing due');
    }

    public function test_the_course_home_offers_review_and_shows_stats_tricky_words_and_unit_freshness(): void
    {
        [$text, $item] = $this->text();
        $learner = User::factory()->create();
        $this->card($learner, $text, $item, ['misses_in_row' => 4]);

        $this->actingAs($learner)->get(route('courses.vocab'))
            ->assertOk()
            ->assertSeeHtml('href="'.route('courses.vocab.review').'"')
            ->assertSee('1 card due')
            ->assertSeeHtml('data-testid="vocab-stats"')
            ->assertSee('Tricky words · 1')
            ->assertSee('get up')
            ->assertSeeHtml('aria-label="Review: due"');
    }

    public function test_the_review_page_opens(): void
    {
        $this->actingAs(User::factory()->create())->get(route('courses.vocab.review'))
            ->assertOk()
            ->assertSee('Nothing due');
    }

    public function test_the_see_step_offers_persian_meanings_when_the_course_has_them(): void
    {
        [$text, $item] = $this->text();
        $item->update(['phrase_fa' => 'بیدار شدن']);
        $text->items()->where('phrase', 'snack')->update(['phrase_fa' => 'میان‌وعده']);
        $text->items()->where('phrase', 'feed')->update(['phrase_fa' => 'غذا دادن']);
        $learner = User::factory()->create();
        $this->card($learner, $text, $item);

        $component = Livewire::actingAs($learner)->test('courses.vocab-review');

        $this->assertEqualsCanonicalizing(['بیدار شدن', 'میان‌وعده', 'غذا دادن'], $component->get('options'));
        $component->call('pick', array_search('بیدار شدن', $component->get('options'), true))
            ->assertSet('verdict', 'right');
    }
}
