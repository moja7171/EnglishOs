<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppLayoutSoundToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sound_mute_toggle_appears_in_the_header_for_an_authenticated_learner(): void
    {
        $learner = User::factory()->create();

        $response = $this->actingAs($learner)->get(route('home'));

        $response->assertOk();
        $response->assertSee('eosSoundEnabled', false);
        $response->assertSee('Mute sound effects', false);
    }
}
