<?php

namespace Tests\Feature;

use App\Models\Mission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5 of [[project_growth_without_discouragement_stories]]: the AI's
 * strictness is the one dial allowed to move as the roadmap goes on, and
 * it must be asymmetric — sharper, never colder, never longer. Most of
 * this file is about what must NOT change alongside the sharpening.
 */
class AsymmetricFeedbackStrictnessTest extends TestCase
{
    use RefreshDatabase;

    private function mission(string $code): Mission
    {
        return new Mission(['code' => $code, 'title' => 'T', 'module' => 'Me', 'outcome' => 'O', 'phases' => []]);
    }

    public function test_the_roadmap_splits_into_the_same_three_stages_as_the_scaffolding_taper(): void
    {
        $this->assertSame(Mission::FEEDBACK_LENIENT, $this->mission('M01')->feedbackLevel());
        $this->assertSame(Mission::FEEDBACK_LENIENT, $this->mission('M08')->feedbackLevel());
        $this->assertSame(Mission::FEEDBACK_STANDARD, $this->mission('M09')->feedbackLevel());
        $this->assertSame(Mission::FEEDBACK_STANDARD, $this->mission('M16')->feedbackLevel());
        $this->assertSame(Mission::FEEDBACK_ATTENTIVE, $this->mission('M17')->feedbackLevel());
        $this->assertSame(Mission::FEEDBACK_ATTENTIVE, $this->mission('M24')->feedbackLevel());
    }

    public function test_the_middle_stage_adds_no_extra_guidance(): void
    {
        $this->assertSame('', $this->mission('M12')->feedbackDepth());
    }

    public function test_early_and_late_guidance_only_moves_the_none_minor_line(): void
    {
        $early = $this->mission('M01')->feedbackDepth();
        $late = $this->mission('M24')->feedbackDepth();

        $this->assertStringContainsString('"none"', $early);
        $this->assertStringContainsString('"minor"', $late);

        // The hard rule: neither variant may touch what counts as
        // "major" — that's the pass bar, and S4/S5 both start from
        // "nothing here may raise it".
        $this->assertStringNotContainsString('"major"', $early);
        foreach (['never changes what counts as "major"'] as $mustAppear) {
            $this->assertStringContainsString($mustAppear, $late);
        }
    }

    public function test_guidance_never_gets_colder_early_or_late(): void
    {
        // The whole point of "asymmetric": strictness may rise, warmth
        // may not fall. None of the harsher-sounding words below should
        // ever appear in either variant's text.
        foreach (['M01', 'M09', 'M17', 'M24'] as $code) {
            $depth = $this->mission($code)->feedbackDepth();

            foreach (['fail', 'bad', 'poor', 'weak', 'disappointing'] as $coldWord) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $coldWord,
                    $depth,
                    "{$code}'s feedbackDepth() reads colder than it should (contains \"{$coldWord}\")"
                );
            }
        }
    }

    public function test_late_missions_hold_a_higher_bar_without_getting_longer(): void
    {
        // "Sharper but never longer" is enforced structurally by
        // SentenceChecker's own 12-word hint cap, which feedbackDepth()
        // never touches — this just pins that neither variant is itself
        // an unreasonably long instruction that would bloat the prompt.
        foreach (['M01', 'M24'] as $code) {
            $depth = $this->mission($code)->feedbackDepth();
            $this->assertLessThan(400, mb_strlen($depth), "{$code}'s feedbackDepth() is suspiciously long");
        }
    }

    public function test_every_sentence_checker_call_site_threads_feedback_depth(): void
    {
        // Real regression coverage for the plumbing itself: every step
        // that calls SentenceChecker::check() must pass feedbackDepth,
        // or that step silently stays stuck at the M01 calibration
        // forever, no matter how far a learner has actually gotten.
        $files = glob(base_path('resources/views/components/missions/steps/*.blade.php'));
        $missing = [];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            if (! str_contains($source, 'SentenceChecker::class)->check(')) {
                continue;
            }

            // Crude but effective: count call sites vs. how many times
            // feedbackDepth is threaded through in the same file. A file
            // with N checks and fewer than N feedbackDepth args has at
            // least one call site that was missed.
            $calls = substr_count($source, 'SentenceChecker::class)->check(');
            $threaded = substr_count($source, 'feedbackDepth: $this->run->mission->feedbackDepth()');

            if ($threaded < $calls) {
                $missing[] = basename($file)." ({$threaded}/{$calls})";
            }
        }

        $this->assertEmpty($missing, 'these step files have a check() call missing feedbackDepth: '.implode(', ', $missing));
    }
}
