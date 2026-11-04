<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Usernames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UsernamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_takes_its_name_and_a_number_when_it_is_held(): void
    {
        $first = User::factory()->create(['name' => 'Ali Rezaei']);
        $second = User::factory()->create(['name' => 'ali  rezaei']);
        $third = User::factory()->create(['name' => 'Ali Rezaei']);

        $this->assertSame('ali_rezaei', $first->username);
        $this->assertSame('ali_rezaei2', $second->username);
        $this->assertSame('ali_rezaei3', $third->username);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namesAndUsernames(): array
    {
        return [
            'a Persian name keeps its letters' => ['علی رضایی', 'علی_رضایی'],
            'Arabic ی and ک become Persian' => ['علي كريمي', 'علی_کریمی'],
            'a half-space joins like a space' => ['مهدی‌زاده', 'مهدی_زاده'],
            'Persian digits become plain' => ['Sara ۲۰', 'sara_20'],
            'only emoji falls back' => ['😀😀', 'user'],
            'one letter falls back' => ['X', 'user'],
            'the app\'s own name is never given' => ['Support', 'support2'],
        ];
    }

    #[DataProvider('namesAndUsernames')]
    public function test_a_name_becomes_a_clean_username(string $name, string $username): void
    {
        $this->assertSame($username, app(Usernames::class)->forName($name));
    }

    public function test_a_long_name_stays_under_the_limit_even_with_a_number(): void
    {
        $name = str_repeat('a', 60);
        User::factory()->create(['name' => $name]);

        $username = User::factory()->create(['name' => $name])->username;

        $this->assertLessThanOrEqual(Usernames::MAX_LENGTH, mb_strlen($username));
        $this->assertStringEndsWith('2', $username);
    }
}
