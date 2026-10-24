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

    public function test_a_finished_missions_photo_on_the_missions_page_opens_its_page(): void
    {
        $learner = User::factory()->create();
        $first = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart($learner, $first));
        $this->makeMission('M02');
        $this->actingAs($learner);

        $this->get(route('missions.index'))
            ->assertSeeHtml('href="'.route('missions.show', [$first, 'overview']).'"')
            ->assertSee("You're here", false);
    }

    public function test_a_needs_review_run_counts_as_finished_on_the_missions_page(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart($learner, $mission), MissionRun::STATUS_NEEDS_REVIEW);
        $this->actingAs($learner);

        $this->get(route('missions.index'))
            ->assertSeeHtml('href="'.route('missions.show', [$mission, 'overview']).'"');
    }

    public function test_the_progress_page_has_no_path_tab(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('progress.index')
            ->assertSee('Overview')
            ->assertDontSeeHtml("setTab('path')");
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

        $response = $this->get(route('missions.show', [$mission, 'overview']));

        $response
            ->assertSee('Present Simple + Adverbs of Frequency')
            ->assertSee('wake up')
            ->assertSee('بیدار شدن')
            ->assertSeeInOrder(['Warm-up', 'https://example.test/second-take.webm', 'Shadowing', 'https://example.test/shadow.webm'], false)
            ->assertDontSee('https://example.test/first-take.webm', false);
    }

    public function test_a_finished_missions_page_opens_each_days_listening_and_daily_talk_with_done_ones_marked(): void
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

        $response = $this->get(route('missions.show', [$mission, 'overview']));

        $response
            ->assertSeeHtml('href="'.route('listening.show', ['M01', 2]).'"')
            ->assertSeeHtml('href="'.route('pi.practice', ['M01', 2]).'"')
            ->assertSeeHtml('aria-label="Day 1 Listening, done"')
            ->assertSeeHtml('aria-label="Day 2 Listening"')
            ->assertSeeHtml('aria-label="Day 1 Daily talk, done"')
            ->assertSeeHtml('aria-label="Day 2 Daily talk, done"');
    }

    public function test_an_old_recap_link_opens_the_missions_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('progress.mission', $this->makeMission('M01')))
            ->assertRedirect(route('missions.show', ['M01', 'overview']));
    }

    public function test_no_recap_for_a_mission_still_in_progress(): void
    {
        $learner = User::factory()->create();
        $mission = $this->makeMission('M01');
        MissionRun::findOrStart($learner, $mission);
        $this->actingAs($learner);

        $this->get(route('missions.show', [$mission, 'overview']))->assertDontSeeHtml('wire:name="progress.mission"');
    }

    public function test_no_recap_for_a_mission_only_someone_else_finished(): void
    {
        $mission = $this->makeMission('M01');
        $this->finish(MissionRun::findOrStart(User::factory()->create(), $mission));
        $this->actingAs(User::factory()->create());

        $this->get(route('missions.show', [$mission, 'overview']))->assertDontSeeHtml('wire:name="progress.mission"');
    }
}
