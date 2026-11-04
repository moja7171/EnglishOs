<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A learner who signs up now holds both AI keys before anything else in
 * the app opens. Earlier accounts and admins are never held.
 */
class RequiredAiKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function learnerOwingKeys(array $attributes = []): User
    {
        $learner = User::factory()->create();
        $learner->forceFill(['ai_keys_required' => true] + $attributes)->save();

        return $learner;
    }

    private function fakeWorkingProviders(): void
    {
        config(['services.groq.judge_models' => []]);
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Hi']]]]]]),
            'api.groq.com/openai/v1/models' => Http::response(['data' => []]),
        ]);
    }

    public function test_signing_up_requires_the_keys(): void
    {
        Livewire::test('auth.register')
            ->set('name', 'New Learner')
            ->set('email', 'new@example.com')
            ->set('password', 'password123')
            ->call('register');

        $this->assertTrue(User::firstWhere('email', 'new@example.com')->ai_keys_required);
    }

    public function test_a_learner_owing_keys_is_sent_to_the_keys_page_from_every_other_page(): void
    {
        $this->actingAs($this->learnerOwingKeys());

        $this->get(route('home'))->assertRedirect(route('ai-keys.setup'));
        $this->get(route('onboarding'))->assertRedirect(route('ai-keys.setup'));
        $this->get(route('profile'))->assertRedirect(route('ai-keys.setup'));
    }

    public function test_one_saved_key_is_not_enough(): void
    {
        $this->actingAs($this->learnerOwingKeys(['gemini_api_key' => 'AIzaSaved']));

        $this->get(route('home'))->assertRedirect(route('ai-keys.setup'));
    }

    public function test_a_saved_key_that_no_longer_decrypts_counts_as_missing(): void
    {
        $learner = $this->learnerOwingKeys(['groq_api_key' => 'gsk_saved']);
        $learner->setRawAttributes(['gemini_api_key' => 'not-decryptable'] + $learner->getAttributes());

        $this->assertTrue($learner->mustAddAiKeys());
    }

    public function test_both_saved_keys_open_the_app(): void
    {
        $this->actingAs($this->learnerOwingKeys(['gemini_api_key' => 'AIzaSaved', 'groq_api_key' => 'gsk_saved']));

        $this->get(route('home'))->assertOk();
        $this->get(route('ai-keys.setup'))->assertRedirect(route('home'));
    }

    public function test_an_earlier_account_without_keys_is_not_held(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('home'))->assertOk();
        $this->get(route('ai-keys.setup'))->assertRedirect(route('home'));
    }

    public function test_an_admin_is_not_held(): void
    {
        $this->actingAs($this->learnerOwingKeys(['is_admin' => true]));

        $this->get(route('home'))->assertOk();
    }

    public function test_a_learner_owing_keys_can_sign_out(): void
    {
        $this->actingAs($this->learnerOwingKeys());

        $this->post(route('logout'))->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_the_page_asks_for_gemini_then_groq_then_goes_to_the_guide_cards(): void
    {
        $this->fakeWorkingProviders();
        $this->actingAs($this->learnerOwingKeys());

        $this->get(route('ai-keys.setup'))->assertOk()->assertSee('aistudio.google.com/apikey');

        Livewire::test('ai-keys-setup')
            ->set('aiKeyInput.gemini', 'AIzaNewKey')
            ->call('saveKey', 'gemini')
            ->assertHasNoErrors()
            ->assertNoRedirect()
            ->assertSee('console.groq.com/keys')
            ->set('aiKeyInput.groq', 'gsk_newkey')
            ->call('saveKey', 'groq')
            ->assertHasNoErrors()
            ->assertRedirect(route('onboarding'));

        $this->assertFalse(auth()->user()->fresh()->mustAddAiKeys());
    }

    public function test_a_rejected_key_keeps_the_learner_on_the_same_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid.']], 400)]);
        $learner = $this->learnerOwingKeys();
        $this->actingAs($learner);

        Livewire::test('ai-keys-setup')
            ->set('aiKeyInput.gemini', 'AIzaWrong')
            ->call('saveKey', 'gemini')
            ->assertHasErrors('aiKeyInput.gemini')
            ->assertNoRedirect()
            ->assertSee('aistudio.google.com/apikey');

        $this->assertNull($learner->fresh()->gemini_api_key);
    }
}
