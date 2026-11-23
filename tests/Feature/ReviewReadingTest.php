<?php

namespace Tests\Feature;

use App\Models\ErrorPatternReview;
use App\Models\GrammarPoint;
use App\Models\NotebookEntry;
use App\Models\SpeakingPrompt;
use App\Models\User;
use App\Services\BidiText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Review's second path, "Mistakes & reminders": the learner only reads one
 * card at a time. Nothing is graded or scheduled — a read card only gets
 * last_reviewed_at and stays away for READING_REPEAT_DAYS.
 */
class ReviewReadingTest extends TestCase
{
    use RefreshDatabase;

    private function learner(): User
    {
        return User::factory()->create();
    }

    private function mistake(User $learner, string $original = 'I have seen her yesterday.', string $better = 'I saw her yesterday.'): NotebookEntry
    {
        return NotebookEntry::factory()->create([
            'learner_id' => $learner->id,
            'original' => $original,
            'better' => $better,
            'note' => 'با yesterday از Past Simple استفاده کن.',
        ]);
    }

    public function test_a_mistake_card_shows_the_wrong_and_right_sentence_with_its_persian_note(): void
    {
        $learner = $this->learner();
        $this->mistake($learner);

        Livewire::actingAs($learner)->test('review.reading')
            ->assertSee('I have seen her yesterday.')
            ->assertSee('I saw her yesterday.')
            ->assertSee('<bdi dir="ltr">Past Simple</bdi>', false);
    }

    public function test_reading_a_card_only_marks_it_read_and_never_moves_its_schedule(): void
    {
        $learner = $this->learner();
        $entry = $this->mistake($learner);
        $dueAt = $entry->next_review_at;

        Livewire::actingAs($learner)->test('review.reading')
            ->call('markRead', 'note', $entry->id)
            ->assertSet('sessionRead', 1)
            ->assertSee('All read');

        $entry->refresh();
        $this->assertNotNull($entry->last_reviewed_at);
        $this->assertSame(0, $entry->repetitions);
        $this->assertTrue($dueAt->equalTo($entry->next_review_at));
        $this->assertNull($entry->learned_at);
    }

    public function test_a_double_tap_cannot_read_a_card_the_learner_did_not_see(): void
    {
        $learner = $this->learner();
        $first = $this->mistake($learner, 'I goes home.', 'I go home.');
        $second = $this->mistake($learner, 'She go home.', 'She goes home.');

        Livewire::actingAs($learner)->test('review.reading')
            ->call('markRead', 'note', $first->id)
            ->call('markRead', 'note', $first->id)
            ->assertSet('sessionRead', 1);

        $this->assertNull($second->fresh()->last_reviewed_at);
    }

    public function test_a_grammar_reminder_shows_its_persian_name_and_example(): void
    {
        $learner = $this->learner();
        GrammarPoint::create([
            'learner_id' => $learner->id,
            'mission_code' => 'M13',
            'focus' => 'Present Perfect vs Past Simple',
            'example_sentence' => 'I have lost my phone.',
            'rule_reminder' => 'Result now: have / has + past participle.',
            'next_review_at' => now(),
        ]);

        Livewire::actingAs($learner)->test('review.reading')
            ->assertSee('Present Perfect vs Past Simple')
            ->assertSee('حال کامل در برابر گذشته ساده')
            ->assertSee('I have lost my phone.');
    }

    public function test_a_day_has_ten_cards_taken_from_every_kind_in_turn_and_ten_more_can_be_asked_for(): void
    {
        $learner = $this->learner();
        foreach (range(1, 12) as $number) {
            $this->mistake($learner, "Wrong {$number}.", "Right {$number}.");
        }
        GrammarPoint::create(['learner_id' => $learner->id, 'mission_code' => 'M01', 'focus' => 'Present simple', 'example_sentence' => 'He works.', 'rule_reminder' => 'Add -s.', 'next_review_at' => now()]);
        ErrorPatternReview::create(['learner_id' => $learner->id, 'category' => 'past_simple', 'last_error' => 'I goed.', 'last_correction' => 'I went.', 'next_review_at' => now()]);

        $today = $learner->dailyReviewItems('talk');

        $this->assertCount(User::DAILY_TALK_LIMIT, $today);
        $this->assertEqualsCanonicalizing(['note', 'grammar', 'error'], $today->take(3)->pluck('type')->all());
        $this->assertSame(10, $learner->dailyReviewCount('talk'));
        $this->assertCount(14, $learner->dailyReviewItems('talk', 1));
    }

    public function test_a_card_read_today_stays_away_for_a_few_days_and_then_comes_round_again(): void
    {
        $learner = $this->learner();
        $entry = $this->mistake($learner);
        $entry->markRead();

        $this->assertSame(0, $learner->dailyReviewCount('talk'));
        $this->assertSame(1, $learner->reviewedTodayCount('talk'));

        Carbon::setTestNow(now()->addDays(User::READING_REPEAT_DAYS + 1));

        $this->assertSame(1, $learner->fresh()->dailyReviewCount('talk'));
    }

    public function test_a_speaking_prompt_is_no_longer_part_of_the_path(): void
    {
        $learner = $this->learner();
        SpeakingPrompt::create(['learner_id' => $learner->id, 'prompt' => 'What time do you wake up?', 'next_review_at' => now()->subMinute()]);

        $this->assertSame(0, $learner->dailyReviewCount('talk'));
    }

    public function test_the_review_hub_counts_the_cards_to_read(): void
    {
        $learner = $this->learner();
        $this->mistake($learner);

        $this->actingAs($learner)->get(route('review.index'))
            ->assertOk()
            ->assertSee('Mistakes &amp; reminders', false)
            ->assertSee('1 to read today');
    }

    public function test_english_inside_persian_is_isolated_so_the_two_never_swap_places(): void
    {
        $bidi = new BidiText;

        $this->assertSame(
            'از <bdi dir="ltr">have / has</bdi> استفاده کن، مثل <bdi dir="ltr">I&#039;ve lost my phone.</bdi>',
            (string) $bidi->fromPlain("از have / has استفاده کن، مثل I've lost my phone."),
        );
        $this->assertSame(
            'با <strong><bdi dir="ltr">he</bdi></strong> یک <bdi dir="ltr">s</bdi>',
            (string) $bidi->fromMarkup('با <strong>he</strong> یک s'),
        );
        $this->assertStringNotContainsString('<script>', (string) $bidi->fromPlain('<script>alert(1)</script>'));
    }
}
