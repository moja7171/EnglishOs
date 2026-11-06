<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DictionaryMeaningPersianTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(array $items): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items])]]]]],
            ]),
        ]);
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('dictionary.meaning', ['word' => 'bank', 'meaning' => 'the land beside a river']))
            ->assertRedirect(route('welcome'));
    }

    public function test_it_gives_the_persian_of_the_picked_meaning(): void
    {
        $this->fakeGemini([['id' => '1', 'persian' => 'ساحل']]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.meaning', ['word' => 'bank', 'meaning' => 'the land beside a river', 'pos' => 'noun']))
            ->assertOk()
            ->assertJsonPath('data.translation_fa', 'ساحل');

        Http::assertSent(fn ($request) => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'the land beside a river'));
    }

    public function test_the_same_meaning_is_asked_about_only_once(): void
    {
        Cache::flush();
        $this->fakeGemini([['id' => '1', 'persian' => 'ساحل']]);
        $learner = User::factory()->create();
        $query = ['word' => 'bank', 'meaning' => 'the land beside a river'];

        $this->actingAs($learner)->getJson(route('dictionary.meaning', $query))->assertOk();
        $this->actingAs($learner)->getJson(route('dictionary.meaning', $query))
            ->assertOk()
            ->assertJsonPath('data.translation_fa', 'ساحل');

        Http::assertSentCount(1);
    }

    public function test_it_says_so_when_gemini_cannot_be_asked(): void
    {
        Cache::flush();
        config(['services.gemini.key' => '']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.meaning', ['word' => 'bank', 'meaning' => 'the land beside a river']))
            ->assertStatus(503);
    }

    public function test_it_needs_a_word_and_a_meaning(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('dictionary.meaning', ['word' => 'bank']))
            ->assertStatus(422);
    }
}
