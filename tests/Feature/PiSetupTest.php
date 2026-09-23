<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A one-time gate before a learner's first mission — see
 * App\Services\PiPrompts and resources/views/components/⚡pi-setup.blade.php.
 * Existing users are grandfathered by the pi_onboarded_at migration's
 * backfill (anyone with program_started_at already set); the forced
 * redirect only ever catches someone truly new. The page itself stays
 * open to everyone though, linked from the account menu so anyone can
 * come back and re-copy the setup messages later.
 */
class PiSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_a_learner_who_has_not_onboarded_pi_is_redirected_to_pi_setup(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => null]);

        $this->actingAs($learner)->get('/')->assertRedirect(route('pi.setup'));
    }

    public function test_a_learner_who_already_onboarded_pi_reaches_home_normally(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get('/')->assertOk();
    }

    public function test_the_setup_page_shows_all_3_roles_with_the_learners_level(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => null, 'cefr_level' => 'A2+']);

        $this->actingAs($learner)->get('/pi-setup')
            ->assertOk()
            ->assertSee('Teacher')
            ->assertSee('Language Partner')
            ->assertSee('Pronunciation Coach')
            ->assertSee('A2+');
    }

    public function test_an_already_onboarded_learner_can_still_revisit_the_setup_page(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get('/pi-setup')->assertOk()->assertSee('Teacher');
    }

    public function test_the_account_menu_links_to_pi_setup(): void
    {
        $learner = User::factory()->create(['pi_onboarded_at' => now()]);

        $this->actingAs($learner)->get('/')->assertSee(route('pi.setup'), false);
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
