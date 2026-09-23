<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_session_without_a_login_timestamp_gets_one_and_stays_logged_in(): void
    {
        $learner = User::factory()->create();

        $response = $this->actingAs($learner)->get('/');

        $response->assertOk();
        $this->assertIsInt(session('auth_login_at'));
    }

    public function test_a_session_within_30_days_of_login_stays_logged_in(): void
    {
        $learner = User::factory()->create();

        $response = $this->withSession(['auth_login_at' => now()->subDays(29)->timestamp])
            ->actingAs($learner)
            ->get('/');

        $response->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_session_older_than_30_days_is_signed_out(): void
    {
        $learner = User::factory()->create();

        $response = $this->withSession(['auth_login_at' => now()->subDays(31)->timestamp])
            ->actingAs($learner)
            ->get('/');

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
