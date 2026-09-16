<?php

namespace Tests\Feature;

use App\Models\Mission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the curriculum's spine — see Mission::roadmapCatalog(). These
 * are the rules that make the 24 missions add up to a real B1 base
 * rather than 24 unrelated topics.
 */
class GrammarMapTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_mission_on_the_roadmap_has_a_grammar_point_and_a_reason(): void
    {
        foreach (Mission::roadmapCatalog() as $code => $entry) {
            $this->assertNotEmpty($entry['grammar'] ?? null, "{$code} has no grammar point");
            $this->assertNotEmpty($entry['why'] ?? null, "{$code} doesn't say why that grammar belongs to that topic");
        }

        $this->assertCount(Mission::TOTAL_ROADMAP_MISSIONS, Mission::roadmapCatalog());
    }

    public function test_no_grammar_point_is_taught_twice(): void
    {
        $points = collect(Mission::roadmapCatalog())->pluck('grammar');

        $this->assertSame(
            $points->count(),
            $points->unique()->count(),
            'a repeated grammar point wastes one of only 24 slots: '.$points->duplicates()->implode(', ')
        );
    }

    public function test_the_map_covers_the_forms_a_real_b1_base_needs(): void
    {
        $map = collect(Mission::roadmapCatalog())->pluck('grammar')->implode(' | ');

        // Not an exhaustive B1 syllabus — the handful whose absence would
        // mean the program simply didn't get there.
        foreach ([
            'Past Simple',
            'Present Perfect',
            'Future',
            'Comparatives',
            'Conditional',
            'Passive',
            'Reported Speech',
            'Relative Clauses',
            'Gerunds vs Infinitives',
            'Modals',
        ] as $form) {
            $this->assertStringContainsString($form, $map, "the roadmap never teaches {$form}");
        }
    }

    public function test_a_seeded_mission_teaches_the_point_the_map_assigned_it(): void
    {
        $catalog = Mission::roadmapCatalog();

        foreach (Mission::all() as $mission) {
            $focus = $mission->stepContent('grammar_in_context')['focus'] ?? null;

            if ($focus === null) {
                continue;
            }

            $planned = $catalog[$mission->code]['grammar'] ?? null;
            $this->assertNotNull($planned, "{$mission->code} is seeded but isn't on the roadmap");

            // The seeded focus may add the mission's own topic in
            // brackets ("… (Work & Study)"); it must still be the
            // planned point, not a different one.
            $this->assertStringStartsWith(
                $planned,
                $focus,
                "{$mission->code} teaches \"{$focus}\" but the roadmap assigned it \"{$planned}\""
            );
        }
    }
}
