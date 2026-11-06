<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FixVocabularyPersianCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(callable $items): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.chat_models' => ['model-a']]);
        Http::fake([
            'generativelanguage.googleapis.com/v1beta/models/model-a:generateContent' => fn ($request) => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => $items($request)])]]]]],
            ]),
        ]);
    }

    private function dictionaryWord(User $learner, string $word, string $persian, array $extra = []): VocabularyWord
    {
        return VocabularyWord::create([
            'learner_id' => $learner->id,
            'word' => $word,
            'meaning' => 'the land beside a river',
            'meaning_fa' => $persian,
            'example' => 'We sat on the bank.',
            'next_review_at' => now()->addDay(),
            ...$extra,
        ]);
    }

    public function test_it_replaces_the_persian_and_keeps_the_old_one(): void
    {
        $word = $this->dictionaryWord(User::factory()->create(), 'bank', 'بانک');
        $this->fakeGemini(fn () => [['id' => (string) $word->id, 'persian' => 'ساحل']]);

        $this->artisan('vocabulary:fix-persian', ['--pause' => 0])->assertSuccessful();

        $word->refresh();
        $this->assertSame('ساحل', $word->meaning_fa);
        $this->assertSame('بانک', $word->meaning_fa_previous);
        $this->assertNotNull($word->meaning_fa_checked_at);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $word = $this->dictionaryWord(User::factory()->create(), 'bank', 'بانک');
        $this->fakeGemini(fn () => [['id' => (string) $word->id, 'persian' => 'ساحل']]);

        $this->artisan('vocabulary:fix-persian', ['--dry-run' => true, '--pause' => 0])
            ->expectsOutputToContain('بانک → ساحل')
            ->assertSuccessful();

        $word->refresh();
        $this->assertSame('بانک', $word->meaning_fa);
        $this->assertNull($word->meaning_fa_checked_at);
    }

    public function test_words_from_a_mission_and_words_already_checked_are_left_alone(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [],
        ]));
        $fromMission = $this->dictionaryWord($learner, 'river', 'رود', ['source_mission_run_id' => $run->id]);
        $checked = $this->dictionaryWord($learner, 'bank', 'ساحل', ['meaning_fa_checked_at' => now()]);
        $this->fakeGemini(fn () => []);

        $this->artisan('vocabulary:fix-persian', ['--pause' => 0])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame('رود', $fromMission->fresh()->meaning_fa);
        $this->assertSame('ساحل', $checked->fresh()->meaning_fa);
    }

    public function test_a_word_gemini_does_not_answer_for_stays_as_it_was_and_is_tried_again_later(): void
    {
        $word = $this->dictionaryWord(User::factory()->create(), 'bank', 'بانک');
        $this->fakeGemini(fn () => []);

        $this->artisan('vocabulary:fix-persian', ['--pause' => 0])->assertSuccessful();

        $word->refresh();
        $this->assertSame('بانک', $word->meaning_fa);
        $this->assertNull($word->meaning_fa_checked_at);
    }

    public function test_it_stops_and_fails_when_gemini_cannot_be_asked(): void
    {
        $word = $this->dictionaryWord(User::factory()->create(), 'bank', 'بانک');
        config(['services.gemini.key' => '']);

        $this->artisan('vocabulary:fix-persian', ['--pause' => 0])->assertFailed();

        $this->assertSame('بانک', $word->fresh()->meaning_fa);
    }
}
