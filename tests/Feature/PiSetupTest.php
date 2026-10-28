<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The voice practice guide — see App\Services\PiPrompts and
 * resources/views/components/⚡pi-setup.blade.php. Never a gate: home offers
 * it as one line until the learner has been through it once, and the page
 * stays linked from the account menu so anyone can come back to it.
 */
class PiSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/welcome');
    }

    public function test_a_learner_who_has_not_seen_the_guide_reaches_home_with_a_one_line_offer(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => null]);
        // Home shows one suggestion at a time and finding your level comes first.
        $learner->placementTests()->create(['level' => 'A2', 'recognition_level' => 'A2', 'detail' => []]);

        $this->actingAs($learner)->get('/')
            ->assertOk()
            ->assertSee('How Daily talk works · 2 min');
    }

    public function test_the_offer_is_gone_once_the_learner_has_been_through_the_guide(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get('/')
            ->assertOk()
            ->assertDontSee('How Daily talk works · 2 min');
    }

    public function test_the_guide_explains_voice_mode_and_the_control_words(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => null]);

        $this->actingAs($learner)->get('/pi-setup')
            ->assertOk()
            ->assertSee('How Daily talk works')
            ->assertSee('turn on voice mode first')
            ->assertSee('Gemini Live')
            ->assertSee('slower');
    }

    public function test_an_already_onboarded_learner_can_still_revisit_the_setup_page(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get('/pi-setup')->assertOk()->assertSee('How Daily talk works');
    }

    public function test_the_daily_talk_page_links_to_pi_setup(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get(route('pi.practice'))->assertSee('href="'.route('pi.setup').'"', false);
    }

    public function test_finishing_setup_stamps_the_timestamp_and_unlocks_home(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => null]);
        $this->actingAs($learner);

        Livewire::test('pi-setup')
            ->call('done')
            ->assertRedirect(route('home'));

        $this->assertNotNull($learner->fresh()->pi_onboarded_at);
        $this->get('/')->assertOk();
    }
}
