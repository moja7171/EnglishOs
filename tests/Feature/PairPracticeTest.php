<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairPracticeSession;
use App\Models\User;
use App\Notifications\PairPracticeInvite;
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
        $this->assertSame(PairPracticeSession::STAGE_WAITING, $session->stage);
    }

    public function test_the_newest_shared_day_comes_first_even_when_its_mission_has_more_words_than_the_round(): void
    {
        $words = collect(range(1, 8))->map(fn (int $i) => ['phrase' => "word {$i}", 'meaning_fa' => "واژه {$i}"])->all();
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'vocabulary_builder_1', 'words' => $words]]],
                ['phase' => 'build', 'label' => 'Day 2', 'steps' => [['key' => 'vocabulary_builder_2', 'words' => [['phrase' => 'newest', 'meaning_fa' => 'تازه‌ترین']]]]],
            ],
        ]);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1, 2]);

        $deck = app(PairPractice::class)->start($me, $friend)->deck['vocabulary'];

        $this->assertCount(PairPractice::VOCABULARY_CARDS, $deck);
        $this->assertContains('newest', collect($deck)->pluck('answer')->all());
    }

    public function test_days_finished_since_the_pair_last_practised_come_before_older_review(): void
    {
        $first = $this->makeMission();
        $second = Mission::create([
            'code' => 'M02',
            'title' => 'My Family',
            'module' => 'Me',
            'outcome' => 'I can talk about my family.',
            'phases' => [
                ['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'vocabulary_builder_1']], 'pair_speaking' => [
                    ['question' => 'Who is in your family?', 'answer' => 'My mum and my dad.', 'words' => ['mum']],
                ]],
            ],
        ]);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);

        foreach ([$me, $friend] as $player) {
            $this->finishDays($player, $first, [1, 2]);
            $this->finishDays($player, $second, [1]);
        }

        PairPracticeSession::factory()->create([
            'user_a_id' => min($me->id, $friend->id),
            'user_b_id' => max($me->id, $friend->id),
            'mission_code' => 'M01',
            'day_number' => 1,
            'stage' => PairPracticeSession::STAGE_DONE,
            'finished_at' => now()->subDay(),
        ]);

        $deck = app(PairPractice::class)->start($me, $friend)->deck['speaking'];

        $this->assertSame(['M02 · Day 1', 'M01 · Day 2', 'M01 · Day 1'], collect($deck)->pluck('source')->all());
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

    public function test_starting_a_round_invites_the_friend_to_a_session_both_can_see(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);

        $this->actingAs($me);
        Livewire::test('pair-practice.index')->call('start', $friend->id)->assertRedirect();

        $session = PairPracticeSession::sole();
        $this->assertSame($me->id, $session->started_by_id);
        $this->assertTrue($session->isWaiting());
        Notification::assertSentTo($friend, PairPracticeInvite::class);

        $this->actingAs($friend)->get(route('pair-practice.show', $session))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('pair-practice.show', $session))->assertForbidden();
    }

    public function test_a_round_cannot_be_started_with_someone_who_is_not_a_mutual_friend(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs(User::factory()->create());

        Livewire::test('pair-practice.index')->call('start', $stranger->id)->assertForbidden();
    }

    public function test_the_invited_friend_joins_and_only_then_the_clock_starts(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();
        [$starter, $friend] = $session->players();

        $this->actingAs($starter);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('Waiting for '.$friend->name)
            ->call('join');
        $this->assertTrue($session->refresh()->isWaiting());

        $this->actingAs($friend);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee($starter->name.' wants to practise')
            ->call('join');

        $session->refresh();
        $this->assertSame(PairPracticeSession::STAGE_VOCABULARY, $session->stage);
        $this->assertNotNull($session->joined_at);
        $this->assertNotNull($session->stage_ends_at);
    }

    public function test_an_invite_expires_after_ten_minutes(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();
        [, $friend] = $session->players();

        $this->travel(PairPracticeSession::INVITE_MINUTES + 1)->minutes();
        $this->actingAs($friend);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('This invite has expired.')
            ->call('join');

        $this->assertTrue($session->refresh()->isWaiting());
        $this->assertSame(0, PairPracticeSession::forPair($session->userA, $session->userB)->open()->count());
    }

    public function test_the_starter_can_share_a_call_link_pasted_with_or_without_https(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();
        [$starter, $friend] = $session->players();

        $this->actingAs($starter);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->set('meetLink', 'javascript:alert(1)')
            ->call('shareLink')
            ->assertHasErrors('meetLink')
            ->set('meetLink', ' meet.google.com/abc-defg-hij ')
            ->call('shareLink')
            ->assertHasNoErrors();

        $this->assertSame('https://meet.google.com/abc-defg-hij', $session->refresh()->meet_link);

        $this->actingAs($friend);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('Open the call')
            ->assertSeeHtml('https://meet.google.com/abc-defg-hij');
    }

    public function test_cancelling_an_invite_leaves_nothing_in_the_history(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();

        $this->actingAs($session->startedBy);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('end')
            ->assertRedirect(route('pair-practice.index'));

        $this->assertModelMissing($session);
    }

    public function test_only_the_judge_sees_the_answer(): void
    {
        $session = PairPracticeSession::factory()->create();
        [$speaker, $judge] = $session->players();

        $this->actingAs($speaker);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('بیدار شدن')
            ->assertDontSee('wake up');

        $this->actingAs($judge);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('wake up')
            ->assertSee('✓ Right');
    }

    public function test_roles_swap_on_every_card_and_points_go_to_the_speaker(): void
    {
        $session = PairPracticeSession::factory()->create();
        $session->deck = [...$session->deck, 'vocabulary' => [...$session->deck['vocabulary'], ['prompt' => 'رفت‌وآمد', 'answer' => 'commute', 'source' => 'M01 · Day 1']]];
        $session->save();
        [$first, $second] = $session->players();

        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session])->call('judge', true, 0);

        $this->actingAs($first);
        Livewire::test('pair-practice.session', ['session' => $session])->call('judge', false, 1);

        $session->refresh();
        $this->assertSame(1, $session->scoreFor($first));
        $this->assertSame(0, $session->scoreFor($second));
        $this->assertSame([$first->id, $second->id], collect($session->results)->pluck('speaker_id')->all());
        $this->assertSame(PairPracticeSession::STAGE_GRAMMAR, $session->stage);
    }

    public function test_the_speaker_cannot_score_and_a_card_is_scored_only_once(): void
    {
        $session = PairPracticeSession::factory()->create();
        $session->deck = [...$session->deck, 'vocabulary' => [...$session->deck['vocabulary'], ['prompt' => 'رفت‌وآمد', 'answer' => 'commute', 'source' => 'M01 · Day 1']]];
        $session->save();
        [$speaker, $judge] = $session->players();

        $this->actingAs($speaker);
        Livewire::test('pair-practice.session', ['session' => $session])->call('judge', true, 0);
        $this->assertSame([], $session->refresh()->results);

        $this->actingAs($judge);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('judge', true, 0)
            ->call('judge', true, 0);

        $this->assertCount(1, $session->refresh()->results);
    }

    public function test_a_speaking_answer_earns_one_point_per_target_word_heard_up_to_two(): void
    {
        $session = PairPracticeSession::factory()->create([
            'stage' => PairPracticeSession::STAGE_SPEAKING,
            'stage_ends_at' => now()->addMinutes(6),
        ]);
        $session->deck = [...$session->deck, 'speaking' => [
            ['question' => 'What time do you wake up?', 'answer' => 'I usually wake up at seven.', 'words' => ['wake up', 'usually', 'seven'], 'source' => 'M01 · Day 1'],
        ]];
        $session->save();
        [$speaker, $judge] = $session->players();

        $this->actingAs($judge);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->set('heardWords', ['wake up', 'usually', 'seven', 'not a target'])
            ->call('nextQuestion', 0);

        $session->refresh();
        $this->assertSame(PairPracticeSession::SPEAKING_MAX_POINTS, $session->scoreFor($speaker));
        $this->assertTrue($session->isFinished());
    }

    public function test_the_timer_moves_on_only_once_the_stage_time_is_up(): void
    {
        $session = PairPracticeSession::factory()->create();
        $this->actingAs($session->startedBy);

        $component = Livewire::test('pair-practice.session', ['session' => $session])->call('timeUp', PairPracticeSession::STAGE_VOCABULARY);
        $this->assertSame(PairPracticeSession::STAGE_VOCABULARY, $session->refresh()->stage);

        $this->travel(181)->seconds();
        $component->call('timeUp', PairPracticeSession::STAGE_VOCABULARY);
        $this->assertSame(PairPracticeSession::STAGE_GRAMMAR, $session->refresh()->stage);

        // The other phone's timer for the same stage fires late: no skip.
        $component->call('timeUp', PairPracticeSession::STAGE_VOCABULARY);
        $this->assertSame(PairPracticeSession::STAGE_GRAMMAR, $session->refresh()->stage);
    }

    public function test_a_word_the_speaker_could_not_say_goes_into_their_own_words_at_the_end(): void
    {
        $session = PairPracticeSession::factory()->create();
        [$first, $second] = $session->players();

        $this->actingAs($second);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('judge', false, 0)
            ->call('end')
            ->assertSee('added to My Words');

        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $first->id, 'word' => 'wake up', 'meaning_fa' => 'بیدار شدن']);
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $second->id, 'word' => 'wake up']);
    }

    public function test_the_invited_friend_sees_join_on_the_friends_list(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);
        app(PairPractice::class)->start($friend, $me);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')->assertSee('Join '.$friend->name);
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
