<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserLevelDescriptionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function levels(): array
    {
        return [
            'A1' => ['A1', 'an absolute beginner (A1)'],
            'A2' => ['A2', 'an elementary (A2)'],
            'B1' => ['B1', 'a B1 (intermediate)'],
            'B2' => ['B2', 'a B2 (upper-intermediate)'],
            'C1' => ['C1', 'a C1 (advanced)'],
        ];
    }

    #[DataProvider('levels')]
    public function test_each_level_has_a_distinct_natural_language_description(string $code, string $expectedStart): void
    {
        $user = User::factory()->create(['cefr_level' => $code]);

        $this->assertStringStartsWith($expectedStart, $user->levelDescription());
    }

    public function test_an_unrecognised_or_missing_level_falls_back_to_the_b1_baseline(): void
    {
        $user = User::factory()->create(['cefr_level' => 'not-a-real-level']);

        $this->assertSame('a B1 (intermediate) English learner', $user->levelDescription());
    }
}
