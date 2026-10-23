<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutSoundToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sound_effects_switch_is_on_profile_and_settings(): void
    {
        $learner = User::factory()->create();

        $response = $this->actingAs($learner)->get(route('profile'));

        $response->assertOk();
        $response->assertSee('eosSoundEnabled', false);
        $response->assertSee('aria-label="Sound effects"', false);
    }
}
