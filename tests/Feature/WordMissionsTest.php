<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\WordMissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WordMissionsTest extends TestCase
{
    use RefreshDatabase;

    private function mission(string $code): Mission
    {
        return Mission::create(['code' => $code, 'title' => "Mission {$code}", 'module' => 'Me', 'outcome' => 'x', 'phases' => []]);
    }

    private function startRun(User $learner, Mission $mission, string $startedAt): MissionRun
    {
        $run = MissionRun::findOrStart($learner, $mission);
        $run->forceFill(['started_at' => $startedAt])->save();

        return $run;
    }

    public function test_a_word_added_on_a_mission_page_goes_to_that_mission_else_to_the_latest_one_started(): void
    {
        $learner = User::factory()->create();
        $first = $this->mission('M01');
        $second = $this->mission('M02');
        $this->startRun($learner, $first, '2026-10-01');
        $this->startRun($learner, $second, '2026-10-10');
        $service = app(WordMissions::class);

        $this->assertSame($first->id, $service->forNewWord($learner, 'm01'));
        $this->assertSame($second->id, $service->forNewWord($learner, null));
        $this->assertSame($second->id, $service->forNewWord($learner, 'M99'));
        $this->assertNull($service->forNewWord(User::factory()->create(), null));
    }

    public function test_the_store_keeps_the_mission_the_word_was_added_in(): void
    {
        $learner = User::factory()->create();
        $first = $this->mission('M01');
        $this->mission('M02');
        $this->startRun($learner, $first, '2026-10-01');
        config(['services.gemini.key' => '']);

        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'sleep', 'mission' => 'M02'])->assertCreated();
        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'wake'])->assertCreated();
        $this->actingAs($learner)->postJson(route('vocabulary.store'), ['word' => 'rest', 'mission' => 'bogus'])->assertUnprocessable();

        $words = $learner->vocabularyWords()->get()->keyBy('word');
        $this->assertSame('M02', $words['sleep']->addedInMission->code);
        $this->assertSame('M01', $words['wake']->addedInMission->code);
    }

    public function test_older_words_are_placed_by_when_they_were_added(): void
    {
        $learner = User::factory()->create();
        $first = $this->mission('M01');
        $second = $this->mission('M02');
        $this->startRun($learner, $first, '2026-10-01');
        $this->startRun($learner, $second, '2026-10-10');
        $early = VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'early', 'created_at' => '2026-10-05']);
        $late = VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'late', 'created_at' => '2026-10-20']);
        $before = VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'before', 'created_at' => '2026-09-20']);
        $lesson = VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'lesson', 'source_mission_run_id' => MissionRun::first()->id]);
        $nobody = VocabularyWord::factory()->create(['learner_id' => User::factory()->create()->id, 'word' => 'nobody']);

        $placed = app(WordMissions::class)->backfill();

        $this->assertSame(3, $placed);
        $this->assertSame($first->id, $early->fresh()->added_in_mission_id);
        $this->assertSame($second->id, $late->fresh()->added_in_mission_id);
        $this->assertSame($first->id, $before->fresh()->added_in_mission_id, 'a word from before any mission goes to the first one started');
        $this->assertNull($lesson->fresh()->added_in_mission_id);
        $this->assertNull($nobody->fresh()->added_in_mission_id);
    }
}
