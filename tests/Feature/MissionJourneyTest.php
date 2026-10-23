<?php

namespace Tests\Feature;

use App\Models\AudioListen;
use App\Models\Evidence;
use App\Models\ListeningLog;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PiChatFeedback;
use App\Models\PiPracticeLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissionJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(string $code, array $steps = [['key' => 'mission_brief']]): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => "Mission {$code}",
            'module' => 'Me',
            'outcome' => "I can do the {$code} thing.",
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => $steps],
                ['phase' => 'practice', 'label' => 'Day 2', 'steps' => [['key' => 'ai_feedback']]],
            ],
        ]);
    }

    private function finish(MissionRun $run, string $status = MissionRun::STATUS_COMPLETE): MissionRun
    {
        $run->update(['status' => $status, 'completed_at' => now()]);

        return $run;
    }

    private function recordAudio(MissionRun $run, string $phase, string $contentRef): void
    {
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => $phase,
            'type' => Evidence::TYPE_AUDIO,
            'content_ref' => $contentRef,
        ]);
    }

    public function test_the_path_shows_done_open_and_locked_missions(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart($learner, $first));
        $second = $this->makeMission('M02');
        $this->makeMission('M03');
        $this->actingAs($learner);

        $component = Livewire::test('progress.index');

        $component
            ->assertSeeHtml('href="'.route('progress.mission', $first).'"')
            ->assertSee('I can do the M01 thing.')
            ->assertSeeHtml('href="'.route('missions.show', [$second, 'overview']).'"')
            ->assertSee('Up next')
            ->assertDontSeeHtml('href="'.route('missions.show', [Mission::where('code', 'M03')->first(), 'overview']).'"')
            ->assertSee('Debate &amp; Discussion', false);
    }

    public function test_a_needs_review_run_counts_as_done_on_the_path(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart($learner, $mission), MissionRun::STATUS_NEEDS_REVIEW);
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSeeHtml('href="'.route('progress.mission', $mission).'"');
    }

    public function test_the_mission_in_progress_shows_which_day_the_learner_is_on(): void
    {
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $this->makeMission('M01'));
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSee("You're here · Day 2 of 2", false);
    }

    public function test_the_summary_line_counts_missions_done_and_listens(): void
    {
        $learner = User::factory()->create();
        $this->finish(MissionRun::findOrStart($learner, $this->makeMission('M01')));
        MissionRun::findOrStart($learner, $this->makeMission('M02'));
        AudioListen::factory()->count(3)->create(['learner_id' => $learner->id]);
        AudioListen::factory()->create();
        $this->actingAs($learner);

        Livewire::test('progress.index')
            ->assertSee('1 mission')
            ->assertSee('3 listens');
    }

    public function test_the_recap_shows_the_grammar_words_and_one_recording_per_step(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01', [
            ['key' => 'mission_brief', 'label' => 'Warm-up'],
            ['key' => 'vocabulary_builder_1', 'label' => 'New Words', 'words' => [['phrase' => 'wake up', 'meaning_fa' => 'بیدار شدن']]],
            ['key' => 'video_shadowing', 'label' => 'Shadowing'],
        ]);
        $run = $this->finish(MissionRun::findOrStart($learner, $mission));
        Evidence::create([
            'mission_run_id' => $run->id,
            'phase' => 'vocabulary_builder_1',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => json_encode(['selected_words' => ['wake up'], 'examples' => []]),
        ]);
        $this->recordAudio($run, 'mission_brief', 'https://example.test/first-take.webm');
        $this->recordAudio($run, 'mission_brief', 'https://example.test/second-take.webm');
        $this->recordAudio($run, 'video_shadowing', json_encode(['line_index' => 0, 'url' => 'https://example.test/shadow.webm']));
        $this->actingAs($learner);

        $response = $this->get(route('progress.mission', $mission));

        $response
            ->assertSee('Present Simple + Adverbs of Frequency')
            ->assertSee('wake up')
            ->assertSee('بیدار شدن')
            ->assertSeeInOrder(['Warm-up', 'https://example.test/second-take.webm', 'Shadowing', 'https://example.test/shadow.webm'], false)
            ->assertDontSee('https://example.test/first-take.webm', false);
    }

    public function test_the_recap_opens_each_day_of_its_listening_and_voice_practice_with_done_days_marked(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart($learner, $mission));
        ListeningLog::factory()->for($learner, 'learner')->create(['listened_on' => now()->subDays(3)->toDateString(), 'day_number' => 1]);
        AudioListen::factory()->pick(3)->create(['learner_id' => $learner->id]);
        PiPracticeLog::factory()->for($learner, 'learner')->create(['practiced_on' => now()->subDays(3)->toDateString(), 'day_number' => 1]);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['day_number' => 2, 'feedback' => ['summary' => 'Done.', 'practice_done' => true]]);
        PiChatFeedback::factory()->for($learner, 'learner')->create(['day_number' => 4, 'feedback' => ['summary' => 'Not it.', 'practice_done' => false]]);
        $this->actingAs($learner);

        $response = $this->get(route('progress.mission', $mission));

        $response
            ->assertSee('Practice again')
            ->assertSeeHtml('href="'.route('listening.show', ['M01', 4]).'"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 4]).'"')
            ->assertSeeHtml('aria-label="Listening, day 1, done"')
            ->assertSeeHtml('aria-label="Listening, day 2"')
            ->assertSeeHtml('aria-label="Listening, day 3, done"')
            ->assertSeeHtml('aria-label="Voice practice, day 1, done"')
            ->assertSeeHtml('aria-label="Voice practice, day 2, done"')
            ->assertSeeHtml('aria-label="Voice practice, day 3"')
            ->assertSeeHtml('aria-label="Voice practice, day 4"');
    }

    public function test_the_recap_is_not_found_for_a_mission_still_in_progress(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01');
        MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        $this->get(route('progress.mission', $mission))->assertNotFound();
    }

    public function test_the_recap_is_not_found_for_a_mission_only_someone_else_finished(): void
    {
        $mission = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart(User::factory()->create(), $mission));
        $this->actingAs(User::factory()->create());

        $this->get(route('progress.mission', $mission))->assertNotFound();
    }
}
