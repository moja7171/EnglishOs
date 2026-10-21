<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeNudge;
use App\Services\PairPractice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PairPracticeTest extends TestCase
{
    use RefreshDatabase;

    private function makeMission(): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [
                    ['key' => 'vocabulary_builder_1', 'words' => [
                        ['phrase' => 'wake up', 'meaning' => 'to stop sleeping', 'meaning_fa' => 'بیدار شدن', 'example' => 'I wake up at seven.'],
                        ['phrase' => 'commute', 'meaning' => 'to travel to work', 'meaning_fa' => 'رفت‌وآمد'],
                    ]],
                ], 'pair_speaking' => [
                    ['question' => 'What time do you wake up?', 'answer' => 'I usually wake up at seven.', 'words' => ['wake up', 'usually']],
                ]],
                ['phase' => 'build', 'label' => 'Day 2', 'steps' => [
                    ['key' => 'grammar_in_context', 'quick_check' => [
                        ['wrong' => 'She go to work.', 'options' => ['She gos to work.', 'She goes to work.'], 'correct' => 1],
                    ]],
                ], 'pair_speaking' => [
                    ['question' => 'How does your mum start her day?', 'answer' => 'She gets up early.', 'words' => ['gets up']],
                ]],
            ],
        ]);
    }

    private function makeMutualFriend(User $me): User
    {
        $friend = User::factory()->create();
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    /**
     * Records Evidence for every step of the given days.
     *
     * @param  list<int>  $days
     */
    private function finishDays(User $user, Mission $mission, array $days): MissionRun
    {
        $run = MissionRun::findOrStart($user, $mission);

        foreach ($days as $day) {
            foreach ($mission->phases[$day - 1]['steps'] as $step) {
                Evidence::create(['mission_run_id' => $run->id, 'phase' => $step['key'], 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);
            }
        }

        return $run;
    }

    public function test_a_round_only_uses_days_both_friends_finished(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1]);

        $session = app(PairPractice::class)->start($me, $friend);

        $this->assertSame(1, $session->day_number);
        $this->assertEqualsCanonicalizing(['wake up', 'commute'], collect($session->deck['vocabulary'])->pluck('answer')->all());
        $this->assertSame([], $session->deck['grammar']);
        $this->assertSame(['What time do you wake up?'], collect($session->deck['speaking'])->pluck('question')->all());
        $this->assertSame(PairPracticeSession::STAGE_VOCABULARY, $session->stage);
    }

    public function test_the_page_shows_which_day_is_locked_and_who_has_to_finish_it(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1]);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')
            ->assertSee('M01 · Day 2 opens once')
            ->assertSee($friend->name.' finishes')
            ->assertSee('Start · 12 min');
    }

    public function test_without_a_shared_finished_day_a_round_cannot_start(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')
            ->assertSee('Finish a day together first')
            ->assertDontSee('Start · 12 min')
            ->call('start', $friend->id)
            ->assertNoRedirect();

        $this->assertDatabaseCount('pair_practice_sessions', 0);
    }

    public function test_starting_a_round_opens_a_session_both_friends_can_see(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);

        $this->actingAs($me);
        Livewire::test('pair-practice.index')->call('start', $friend->id)->assertRedirect();

        $session = PairPracticeSession::sole();
        $this->assertSame($me->id, $session->started_by_id);

        $this->actingAs($friend)->get(route('pair-practice.show', $session))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('pair-practice.show', $session))->assertForbidden();
    }

    public function test_a_round_cannot_be_started_with_someone_who_is_not_a_mutual_friend(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->create());

        Livewire::test('pair-practice.index')->call('start', $stranger->id)->assertForbidden();
    }

    public function test_roles_swap_on_every_card_and_points_go_to_the_speaker(): void
    {
        $session = PairPracticeSession::factory()->create();
        $session->deck = [...$session->deck, 'vocabulary' => [...$session->deck['vocabulary'], ['prompt' => 'رفت‌وآمد', 'answer' => 'commute', 'source' => 'M01 · Day 1']]];
        $session->save();
        [$first, $second] = $session->players();

        $this->actingAs($first);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('reveal')
            ->call('judge', true)
            ->call('reveal')
            ->call('judge', false);

        $session->refresh();
        $this->assertSame(1, $session->scoreFor($first));
        $this->assertSame(0, $session->scoreFor($second));
        $this->assertSame([$first->id, $second->id], collect($session->results)->pluck('speaker_id')->all());
        $this->assertSame(PairPracticeSession::STAGE_GRAMMAR, $session->stage);
    }

    public function test_a_speaking_answer_earns_one_point_per_target_word_heard(): void
    {
        $session = PairPracticeSession::factory()->create([
            'stage' => PairPracticeSession::STAGE_SPEAKING,
            'stage_ends_at' => now()->addMinutes(6),
        ]);
        $speaker = $session->speaker();

        $this->actingAs($speaker);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->set('heardWords', ['wake up', 'usually', 'not a target'])
            ->call('nextQuestion');

        $session->refresh();
        $this->assertSame(2, $session->scoreFor($speaker));
        $this->assertTrue($session->isFinished());
    }

    public function test_the_timer_moves_on_only_once_the_stage_time_is_up(): void
    {
        $session = PairPracticeSession::factory()->create();
        $this->actingAs($session->startedBy);

        $component = Livewire::test('pair-practice.session', ['session' => $session])->call('timeUp');
        $this->assertSame(PairPracticeSession::STAGE_VOCABULARY, $session->refresh()->stage);

        $this->travel(181)->seconds();
        $component->call('timeUp');
        $this->assertSame(PairPracticeSession::STAGE_GRAMMAR, $session->refresh()->stage);
    }

    public function test_a_word_the_speaker_could_not_say_goes_into_their_own_words_at_the_end(): void
    {
        $session = PairPracticeSession::factory()->create();
        [$first, $second] = $session->players();

        $this->actingAs($first);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('reveal')
            ->call('judge', false)
            ->call('end')
            ->assertSee('added to My Words');

        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $first->id, 'word' => 'wake up', 'meaning_fa' => 'بیدار شدن']);
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $second->id, 'word' => 'wake up']);
    }

    public function test_the_pair_streak_counts_only_days_both_friends_finished(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $pairPractice = app(PairPractice::class);

        $this->travelTo(now()->subDay());
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);
        $this->travelBack();

        $this->assertSame(1, $pairPractice->pairStreak($me, $friend));

        $this->finishDays($me, $mission, [2]);
        $this->assertSame(1, $pairPractice->pairStreak($me, $friend));

        $this->finishDays($friend, $mission, [2]);
        $this->assertSame(2, $pairPractice->pairStreak($me, $friend));
    }

    public function test_a_friend_can_be_nudged_once_a_day(): void
    {
        Notification::fake();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')
            ->call('nudge', $friend->id)
            ->call('nudge', $friend->id)
            ->assertSee('Nudged ✓');

        Notification::assertSentToTimes($friend, PairPracticeNudge::class, 1);
    }

    public function test_only_a_mutual_friend_can_be_nudged(): void
    {
        Notification::fake();
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->create());

        Livewire::test('pair-practice.index')->call('nudge', $stranger->id)->assertForbidden();

        Notification::assertNothingSent();
    }
}
