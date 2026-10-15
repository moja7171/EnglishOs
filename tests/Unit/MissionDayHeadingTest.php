<?php

namespace Tests\Unit;

use App\Models\Mission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MissionDayHeadingTest extends TestCase
{
    public function test_a_named_day_shows_its_name_after_the_number(): void
    {
        $this->assertSame('Day 2 · Build', Mission::dayHeading(2, 'Build'));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function labelsThatOnlyRepeatTheNumber(): array
    {
        return [
            'same number' => ['Day 1'],
            'other number' => ['Day 4'],
            'lowercase' => ['day 3'],
            'padded' => ['  Day 1 '],
            'empty' => [''],
            'null' => [null],
        ];
    }

    #[DataProvider('labelsThatOnlyRepeatTheNumber')]
    public function test_a_label_that_only_repeats_the_number_is_dropped(?string $label): void
    {
        $this->assertSame('Day 1', Mission::dayHeading(1, $label));
    }
}
