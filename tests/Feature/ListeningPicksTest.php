<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Services\ListeningPicks;
use Tests\TestCase;

class ListeningPicksTest extends TestCase
{
    public function test_every_program_day_has_three_picks_with_a_title_and_an_https_link(): void
    {
        $picks = app(ListeningPicks::class);
        $urls = [];

        foreach (array_keys(Mission::roadmapCatalog()) as $code) {
            foreach (range(1, 4) as $day) {
                $trio = $picks->forDay($code, $day);

                $this->assertCount(3, $trio ?? [], "$code day $day should have 3 picks");

                foreach ($trio as $pick) {
                    $this->assertNotSame('', trim($pick['title']), "$code day $day has a pick without a title");
                    $this->assertStringStartsWith('https://', $pick['url'], "$code day $day: {$pick['title']}");
                    $urls[] = $pick['url'];
                }
            }
        }

        $this->assertCount(288, $urls);
        $this->assertCount(288, array_unique($urls), 'an episode is repeated across days');
    }

    public function test_a_page_link_transcript_always_comes_with_its_own_address(): void
    {
        $picks = app(ListeningPicks::class);

        foreach (array_keys(Mission::roadmapCatalog()) as $code) {
            foreach (range(1, 4) as $day) {
                foreach ($picks->forDay($code, $day) as $pick) {
                    if ($pick['tx'] === 'link') {
                        $this->assertStringStartsWith('https://', (string) $pick['txUrl'], "$code day $day: {$pick['title']}");
                    }
                }
            }
        }
    }

    public function test_the_second_day_of_each_early_mission_carries_exactly_one_grammar_pick(): void
    {
        $picks = app(ListeningPicks::class);

        foreach (range(1, 15) as $number) {
            $code = sprintf('M%02d', $number);
            $grammarPicks = array_filter($picks->forDay($code, 2), fn (array $pick) => $pick['grammar']);

            $this->assertCount(1, $grammarPicks, "$code day 2");
        }
    }

    public function test_an_unknown_mission_or_day_has_no_picks(): void
    {
        $picks = app(ListeningPicks::class);

        $this->assertNull($picks->forDay('M25', 1));
        $this->assertNull($picks->forDay('M01', 5));
        $this->assertNull($picks->forDay('M01', 0));
    }

    public function test_missing_optional_fields_fall_back_to_safe_defaults(): void
    {
        $pick = app(ListeningPicks::class)->forDay('M01', 1)[0];

        $this->assertSame('Routines', $pick['title']);
        $this->assertNull($pick['by']);
        $this->assertNull($pick['txUrl']);
        $this->assertFalse($pick['apple']);
        $this->assertFalse($pick['grammar']);
    }

    public function test_today_is_the_open_missions_current_day(): void
    {
        $day = app(ListeningPicks::class)->dayFor([
            'kind' => 'mission_day',
            'mission' => new Mission(['code' => 'M03']),
            'dayNumber' => 2,
        ]);

        $this->assertSame(['missionCode' => 'M03', 'dayNumber' => 2], $day);
    }

    public function test_between_missions_today_is_day_1_of_the_next_mission(): void
    {
        $picks = app(ListeningPicks::class);

        $this->assertSame(
            ['missionCode' => 'M02', 'dayNumber' => 1],
            $picks->dayFor(['kind' => 'start_next', 'nextMissionCode' => 'M02', 'dayNumber' => 1]),
        );
        $this->assertSame(
            ['missionCode' => 'M07', 'dayNumber' => 1],
            $picks->dayFor(['kind' => 'checkpoint', 'nextMissionCode' => 'M07', 'dayNumber' => 1]),
        );
    }

    public function test_there_is_no_today_once_the_program_is_finished(): void
    {
        $this->assertNull(app(ListeningPicks::class)->dayFor(['kind' => 'finished']));
    }

    public function test_program_position_counts_four_days_per_mission(): void
    {
        $picks = app(ListeningPicks::class);

        $this->assertSame(0, $picks->indexOf('M01', 1));
        $this->assertSame(9, $picks->indexOf('M03', 2));
        $this->assertSame(95, $picks->indexOf('M24', 4));
    }
}
