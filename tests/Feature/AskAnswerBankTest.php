<?php

namespace Tests\Feature;

use App\Models\AskAnswerItem;
use App\Services\AskAnswerChecker;
use Database\Seeders\AskAnswerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AskAnswerBankTest extends TestCase
{
    use RefreshDatabase;

    /** Words that would make a sentence sensitive or silly for a learner's real life. */
    private const BANNED = ['beer', 'wine', 'vodka', 'alcohol', 'boyfriend', 'girlfriend', 'politics', 'religion', 'cat on the table'];

    public function test_the_seeder_loads_forty_situations_for_m11_and_can_run_again(): void
    {
        $this->seed(AskAnswerSeeder::class);
        $this->seed(AskAnswerSeeder::class);

        $this->assertSame(40, AskAnswerItem::where('mission_number', 11)->count());

        foreach ([1, 2, 3, 4] as $day) {
            $this->assertSame(5, AskAnswerItem::where('mission_number', 11)->where('day', $day)->where('kind', AskAnswerItem::KIND_SIMPLE)->count(), "day $day plain");
            $this->assertSame(5, AskAnswerItem::where('mission_number', 11)->where('day', $day)->where('kind', AskAnswerItem::KIND_FOLLOWUP)->count(), "day $day follow-up");
        }
    }

    public function test_each_written_mission_has_forty_situations_ten_on_each_day(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::pluck('mission_number')->unique() as $mission) {
            $this->assertSame(40, AskAnswerItem::where('mission_number', $mission)->count(), "M$mission");

            foreach ([1, 2, 3, 4] as $day) {
                $this->assertSame(10, AskAnswerItem::where('mission_number', $mission)->where('day', $day)->count(), "M$mission day $day");
            }
        }
    }

    public function test_every_situation_has_three_complete_levels_on_both_sides(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all() as $item) {
            $this->assertSame($item->kind === AskAnswerItem::KIND_FOLLOWUP, filled($item->context_fa), $item->slug.' context line');

            foreach ([AskAnswerItem::SIDE_QUESTION, AskAnswerItem::SIDE_ANSWER] as $side) {
                foreach (AskAnswerItem::LEVELS as $level) {
                    $data = $item->level($side, $level);
                    $where = "{$item->slug} {$side} L{$level}";

                    $this->assertNotSame('', trim($data['fa']), "$where Persian");
                    $this->assertNotSame('', trim($data['en']), "$where English");
                    $this->assertNotEmpty($data['fa_extra'], "$where optional Persian");
                    $this->assertNotEmpty($data['en_extra'], "$where optional English");

                    if (str_word_count($data['en']) > 3) {
                        $this->assertNotEmpty($data['wrong'], "$where known wrong version");
                    }

                    $this->assertSame($level, array_search($data, $item->{$side}, true), "$where is stored at its level");

                    foreach ($data['wrong'] as $wrong) {
                        $this->assertNotSame('', trim($wrong['why']), "$where reason");
                        $this->assertNotSame(AskAnswerChecker::normalize($data['en']), AskAnswerChecker::normalize($wrong['text']), "$where wrong version equals the target");

                        foreach ($data['alt'] as $alt) {
                            $this->assertNotSame(AskAnswerChecker::normalize($alt), AskAnswerChecker::normalize($wrong['text']), "$where wrong version equals an accepted one");
                        }
                    }
                }
            }
        }
    }

    public function test_harder_levels_are_longer_not_filled_with_new_vocabulary(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all() as $item) {
            foreach ([AskAnswerItem::SIDE_QUESTION, AskAnswerItem::SIDE_ANSWER] as $side) {
                $first = str_word_count($item->level($side, 1)['en']);
                $last = str_word_count($item->level($side, 3)['en']);

                $this->assertGreaterThanOrEqual($first, $last, "{$item->slug} {$side}: level 3 is not shorter than level 1");
            }

            $this->assertLessThanOrEqual(10, str_word_count($item->level(AskAnswerItem::SIDE_QUESTION, 1)['en']), $item->slug.' level 1 question is short');
        }
    }

    public function test_no_sensitive_or_silly_topics(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all() as $item) {
            $text = mb_strtolower(json_encode($item->only(['questions', 'answers']), JSON_UNESCAPED_UNICODE));

            foreach (self::BANNED as $word) {
                $this->assertStringNotContainsString($word, $text, "{$item->slug} mentions {$word}");
            }
        }
    }

    public function test_the_situations_are_all_different(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all()->groupBy('mission_number') as $mission => $items) {
            $questions = $items->map(fn (AskAnswerItem $item) => AskAnswerChecker::normalize($item->level(AskAnswerItem::SIDE_QUESTION, 1)['en']));

            $this->assertSame($questions->count(), $questions->unique()->count(), "M$mission repeats a level 1 question");
        }

        $this->assertSame(AskAnswerItem::count(), AskAnswerItem::pluck('slug')->unique()->count());
    }

    public function test_an_answer_fits_the_question_of_its_level(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all() as $item) {
            foreach (AskAnswerItem::LEVELS as $level) {
                $question = $item->level(AskAnswerItem::SIDE_QUESTION, $level)['en'];
                $answer = $item->level(AskAnswerItem::SIDE_ANSWER, $level)['en'];
                $where = "{$item->slug} L{$level}: \"$question\" / \"$answer\"";
                $startsYesNo = preg_match('/^(yes|no)\s*[,.!]/i', $answer) === 1;
                $startsYes = preg_match('/^yes\s*[,.!]/i', $answer) === 1;
                $asksYesNo = preg_match('/^(do|does|did|is|are|can|have|has|will|would|could)\b/i', $question) === 1;

                // A question with "or" is answered with the choice, never with yes (a no can deny the first option); a wh-question never starts with yes or no.
                if ($asksYesNo && preg_match('/\bor\b/i', explode('?', $question)[0]) === 1) {
                    $this->assertFalse($startsYes, "$where: a choice is answered with the choice");
                }

                if (! $asksYesNo && preg_match('/^(what|where|when|why|who|which|how)\b/i', $question) === 1) {
                    $this->assertFalse($startsYesNo, "$where: a wh-question is not answered with yes or no");
                }
            }
        }
    }

    public function test_seeding_again_removes_a_situation_that_left_the_files(): void
    {
        $this->seed(AskAnswerSeeder::class);

        $old = AskAnswerItem::factory()->create(['mission_number' => 4, 'day' => 1, 'slug' => 'm04-d1-no-longer-written']);

        $this->seed(AskAnswerSeeder::class);

        $this->assertModelMissing($old);
        $this->assertSame(40, AskAnswerItem::where('mission_number', 4)->count());
    }

    public function test_a_question_that_asks_two_things_in_a_row_is_answered_in_both_parts(): void
    {
        $this->seed(AskAnswerSeeder::class);

        foreach (AskAnswerItem::all() as $item) {
            foreach (AskAnswerItem::LEVELS as $level) {
                $question = preg_replace('/\s*Why\?\s*$/', '', $item->level(AskAnswerItem::SIDE_QUESTION, $level)['en']);
                $answer = $item->level(AskAnswerItem::SIDE_ANSWER, $level)['en'];

                if (substr_count((string) $question, '?') >= 2) {
                    $this->assertMatchesRegularExpression('/\b(and|but|so)\b|,|[.!?]\s+\S/', $answer, "{$item->slug} L{$level}: \"$question\" is answered with one part only: \"$answer\"");
                }
            }
        }
    }
}
