<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * users.last_seen_at follows the learner's visits, written at most once
 * every few minutes and never moving updated_at.
 */
class TouchLastSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_records_when_the_learner_was_last_seen(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create(['updated_at' => now()->subDay()]);

        $this->actingAs($learner)->get(route('notebook.index'))->assertOk();

        $learner->refresh();
        $this->assertTrue($learner->last_seen_at->equalTo(now()));
        $this->assertTrue($learner->updated_at->equalTo(now()->subDay()));
    }

    public function test_visits_a_few_minutes_apart_write_once(): void
    {
        $this->freezeSecond();
        $seenAt = now()->subMinutes(3);
        $learner = User::factory()->create(['last_seen_at' => $seenAt]);

        $this->actingAs($learner)->get(route('notebook.index'))->assertOk();

        $this->assertTrue($learner->fresh()->last_seen_at->equalTo($seenAt));
    }

    public function test_a_visit_after_the_pause_moves_last_seen(): void
    {
        $this->freezeSecond();
        $learner = User::factory()->create(['last_seen_at' => now()->subMinutes(10)]);

        $this->actingAs($learner)->get(route('notebook.index'))->assertOk();

        $this->assertTrue($learner->fresh()->last_seen_at->equalTo(now()));
    }
}
