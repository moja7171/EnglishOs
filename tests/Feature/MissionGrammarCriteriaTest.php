<?php

namespace Tests\Feature;

use App\Models\Mission;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the grammar-in-context check of M01–M14 holds learners to: the
 * Persian-speaker slips that only earn a reminder ("minor"), the one that
 * rejects the sentence ("major"), and one slip recycled from an earlier mission.
 */
class MissionGrammarCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private function grammar(string $code): array
    {
        return Mission::where('code', $code)->firstOrFail()->stepContent('grammar_in_context');
    }

    public function test_every_mission_from_m02_on_reminds_about_one_earlier_slip(): void
    {
        $this->seed(MissionSeeder::class);

        foreach (range(2, 14) as $number) {
            $code = sprintf('M%02d', $number);

            $this->assertNotEmpty(
                $this->grammar($code)['grammar_minor_criteria'] ?? null,
                "{$code} has no grammar_minor_criteria."
            );
        }
    }

    public function test_articles_are_a_reminder_from_m03_and_never_a_reason_to_reject(): void
    {
        $this->seed(MissionSeeder::class);

        foreach (range(2, 14) as $number) {
            $code = sprintf('M%02d', $number);
            $grammar = $this->grammar($code);
            $mentionsArticles = str_contains($grammar['grammar_minor_criteria'], 'a / an / the');

            $this->assertSame($number >= 3, $mentionsArticles, "{$code}: articles are a reminder from M03 on, not before.");
            $this->assertStringNotContainsString('article', $grammar['grammar_major_criteria'], "{$code}: an article slip must not reject a sentence.");
        }
    }

    public function test_a_sentence_with_no_subject_is_rejected_from_m09_on(): void
    {
        $this->seed(MissionSeeder::class);

        foreach (range(1, 14) as $number) {
            $code = sprintf('M%02d', $number);

            $this->assertSame(
                $number >= 9,
                str_contains($this->grammar($code)['grammar_major_criteria'], 'a verb with no subject'),
                "{$code}: a verb with no subject is a major slip from M09 on only."
            );
        }
    }
}
