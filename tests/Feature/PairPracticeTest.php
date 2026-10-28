<?php

namespace Tests\Feature;

use App\Models\DayActivity;
use App\Models\ErrorLogItem;
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

    /**
     * A round on its first card with $count word cards ("word 0", …) ahead
     * of the factory's grammar and speaking cards.
     */
    private function sessionWithWordCards(int $count): PairPracticeSession
    {
        $session = PairPracticeSession::factory()->create();
        $session->deck = [...$session->deck, 'vocabulary' => collect(range(0, $count - 1))
            ->map(fn (int $i) => ['prompt' => "واژه {$i}", 'answer' => "word {$i}", 'source' => 'M01 · Day 1'])
            ->all()];
        $session->save();

        return $session;
    }

    /**
     * One mission whose days each teach $words words ("d1 word 1", …), so
     * a round has to choose among them.
     */
    private function makeWordyMission(int $days, int $words, array $speaking = []): Mission
    {
        return Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk about my daily routine.',
            'phases' => collect(range(1, $days))->map(fn (int $day) => [
                'phase' => 'foundation',
                'label' => "Day {$day}",
                'steps' => [['key' => "vocabulary_builder_{$day}", 'words' => collect(range(1, $words))
                    ->map(fn (int $i) => ['phrase' => "d{$day} word {$i}", 'meaning_fa' => "واژه {$day}-{$i}"])
                    ->all()]],
                'pair_speaking' => $speaking,
            ])->all(),
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

    public function test_the_page_shows_the_next_day_and_offers_only_shared_days_to_pick(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1]);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')
            ->assertSee('M01 · Day 1')
            ->assertSee('Practice · 12 min')
            ->assertSeeHtml("toggle('M01-1')")
            ->assertDontSeeHtml("toggle('M01-2')")
            ->assertSee('Not finished by both of you yet');
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
            ->assertDontSee('Practice · 12 min')
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

    public function test_a_cancelled_invite_leaves_nothing_in_the_history_and_the_friend_is_told(): void
    {
        $session = PairPracticeSession::factory()->waiting()->create();
        [$starter, $friend] = $session->players();

        $this->actingAs($starter);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('end')
            ->assertRedirect(route('pair-practice.index'));

        $session->refresh();
        $this->assertTrue($session->isCancelled());
        $this->assertNull($session->finished_at);
        $this->assertSame(0, PairPracticeSession::forPair($starter, $friend)->open()->count());

        $this->actingAs($friend);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee($starter->name.' cancelled the invite.')
            ->call('join');
        $this->assertTrue($session->refresh()->isCancelled());
    }

    public function test_a_round_ended_too_early_does_not_count(): void
    {
        $session = $this->sessionWithWordCards(6);
        [$first, $second] = $session->players();

        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('Too short to count yet.')
            ->call('judge', false, 0)
            ->call('end');

        $session->refresh();
        $this->assertTrue($session->isCancelled());
        $this->assertNull($session->finished_at);
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $first->id]);

        $this->actingAs($first);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee($second->name.' ended the round.')
            ->assertSee('Too short to count');
    }

    public function test_ending_a_round_tells_the_other_phone_who_ended_it(): void
    {
        $session = $this->sessionWithWordCards(6);
        [$first, $second] = $session->players();

        foreach (range(0, PairPracticeSession::MIN_COUNTED_CARDS - 1) as $card) {
            $this->actingAs($card % 2 === 0 ? $second : $first);
            Livewire::test('pair-practice.session', ['session' => $session->refresh()])->call('judge', true, $card);
        }

        $this->actingAs($first);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->assertSee('Scores so far are saved.')
            ->call('end');

        $session->refresh();
        $this->assertTrue($session->isFinished());
        $this->assertSame($first->id, $session->ended_by_id);

        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('Well done, both of you!')
            ->assertSee($first->name.' ended the round.');
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

    public function test_the_card_just_scored_stays_on_both_phones_with_its_answer_before_the_roles_swap(): void
    {
        $session = PairPracticeSession::factory()->create();
        $session->deck = [...$session->deck, 'vocabulary' => [...$session->deck['vocabulary'], ['prompt' => 'رفت‌وآمد', 'answer' => 'commute', 'source' => 'M01 · Day 1']]];
        $session->save();
        [$speaker, $judge] = $session->players();

        $this->actingAs($judge);
        Livewire::test('pair-practice.session', ['session' => $session])
            ->call('judge', false, 0)
            ->assertSee('Not yet — the answer')
            ->assertSee('wake up')
            ->assertSee('Saved · 🔄 Next: your turn');

        $this->actingAs($speaker);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->assertSee("Not yet — it's")
            ->assertSee('wake up')
            ->assertSee('🔄 Next: you listen');

        $this->travel(PairPracticeSession::REVEAL_WINDOW_SECONDS + 1)->seconds();

        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->assertDontSee('wake up')
            ->assertSee('commute');
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
        $session = $this->sessionWithWordCards(PairPracticeSession::MIN_COUNTED_CARDS);
        [$first, $second] = $session->players();

        foreach (range(0, PairPracticeSession::MIN_COUNTED_CARDS - 1) as $card) {
            $this->actingAs($card % 2 === 0 ? $second : $first);
            Livewire::test('pair-practice.session', ['session' => $session->refresh()])->call('judge', $card !== 0, $card);
        }

        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->call('end')
            ->assertDontSee('For your review (saved)');

        $this->actingAs($first);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->assertSee('For your review (saved)')
            ->assertSee('word 0');

        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $first->id, 'word' => 'word 0', 'meaning_fa' => 'واژه 0']);
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $second->id, 'word' => 'word 0']);
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

    public function test_the_pair_streak_counts_only_days_both_friends_kept_their_streak(): void
    {
        $me = User::factory()->create(['streak_ring_since' => now()->subDays(3)->toDateString()]);
        $friend = $this->makeMutualFriend($me);
        $friend->forceFill(['streak_ring_since' => now()->subDays(3)->toDateString()])->save();
        $pairPractice = app(PairPractice::class);
        $keep = function (User $user, int $daysAgo): void {
            foreach (['test:1', 'test:2'] as $activity) {
                DayActivity::factory()->create(['learner_id' => $user->id, 'on_date' => now()->subDays($daysAgo)->toDateString(), 'activity' => $activity]);
            }
        };

        $keep($me, 1);
        $keep($friend, 1);
        $this->assertSame(1, $pairPractice->pairStreak($me, $friend));

        $keep($me, 0);
        $this->assertSame(1, $pairPractice->pairStreak($me, $friend));

        $keep($friend, 0);
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
            ->assertSeeHtml('aria-label="Nudged"');

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

    public function test_a_round_can_draw_from_chosen_days_only(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1, 2]);

        $session = app(PairPractice::class)->start($me, $friend, ['M01-1']);

        $this->assertSame(['M01-1'], $session->days);
        $this->assertSame('M01 · Day 1', $session->daysLabel());
        $this->assertEqualsCanonicalizing(['wake up', 'commute'], collect($session->deck['vocabulary'])->pluck('answer')->all());
        $this->assertSame([], $session->deck['grammar']);
        $this->assertSame(['What time do you wake up?'], collect($session->deck['speaking'])->pluck('question')->all());
    }

    public function test_a_day_that_is_not_shared_cannot_be_chosen_and_the_round_falls_back_to_the_latest(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1]);

        $session = app(PairPractice::class)->start($me, $friend, ['M01-2']);

        $this->assertNull($session->days);
        $this->assertSame(1, $session->day_number);
    }

    public function test_chosen_days_share_the_round_in_turn(): void
    {
        $mission = $this->makeWordyMission(days: 3, words: 8);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2, 3]);
        $this->finishDays($friend, $mission, [1, 2, 3]);

        $deck = app(PairPractice::class)->start($me, $friend, ['M01-1', 'M01-3'])->deck['vocabulary'];

        $this->assertSame(['M01 · Day 1' => 4, 'M01 · Day 3' => 4], collect($deck)->countBy('source')->sortKeys()->all());
    }

    public function test_the_days_label_joins_consecutive_days_into_a_range(): void
    {
        $this->assertSame('M01 · Days 1–3, 5 + M02 · Day 2', PairPracticeSession::describeDays(['M01-1', 'M01-2', 'M01-3', 'M01-5', 'M02-2']));
    }

    public function test_cards_seen_in_the_last_day_go_to_the_back_and_a_missed_one_comes_first(): void
    {
        $mission = $this->makeWordyMission(days: 1, words: 10);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);
        $pairPractice = app(PairPractice::class);

        $first = $pairPractice->start($me, $friend);
        $seen = collect($first->deck['vocabulary'])->pluck('answer');
        $first->update([
            'stage' => PairPracticeSession::STAGE_DONE,
            'finished_at' => now(),
            'results' => $seen->keys()->map(fn (int $card) => ['stage' => 'vocabulary', 'card' => $card, 'speaker_id' => $me->id, 'points' => 0, 'correct' => $card !== 0])->all(),
        ]);

        $next = collect($pairPractice->start($me, $friend)->deck['vocabulary'])->pluck('answer');
        $unseen = collect(range(1, 10))->map(fn (int $i) => "d1 word {$i}")->diff($seen);

        $this->assertContains($seen[0], $next);
        $this->assertEmpty($unseen->diff($next));

        // A day later nothing counts as just seen any more.
        $this->travel(PairPractice::SEEN_HOURS + 1)->hours();
        $this->assertCount(PairPractice::VOCABULARY_CARDS, $pairPractice->start($friend, $me)->deck['vocabulary']);
    }

    public function test_each_player_answers_a_sentence_from_their_own_error_log(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $run = $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1, 2]);
        ErrorLogItem::create(['mission_run_id' => $run->id, 'error' => 'I goes home.', 'correction' => 'I go home.']);

        // The friend starts, so the friend would answer the first card.
        $session = app(PairPractice::class)->start($friend, $me);
        $own = collect($session->deck['grammar'])->firstWhere('owner_id', $me->id);

        $this->assertSame('I goes home.', $own['prompt']);
        $this->assertCount(2, $session->deck['grammar']);

        $session->update(['stage' => PairPracticeSession::STAGE_GRAMMAR, 'joined_at' => now(), 'deck' => [...$session->deck, 'grammar' => [$own]]]);

        $this->assertTrue($session->speaker()->is($me));
        $this->assertTrue($session->judge()->is($friend));
    }

    public function test_speaking_adds_talk_cards_made_from_the_rounds_own_words(): void
    {
        $mission = $this->makeWordyMission(days: 1, words: 8, speaking: [
            ['question' => 'What do you do every morning?', 'answer' => 'I make coffee.', 'words' => ['make']],
        ]);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);

        $session = app(PairPractice::class)->start($me, $friend);
        $talks = collect($session->deck['speaking'])->where('type', 'talk');
        $words = collect($session->deck['vocabulary'])->pluck('answer');

        $this->assertSame('What do you do every morning?', $session->deck['speaking'][0]['question']);
        $this->assertCount(2, $talks);
        $talks->each(fn (array $talk) => $this->assertEmpty(collect($talk['words'])->diff($words)) ?: $this->assertCount(PairPractice::WORD_TALK_SIZE, $talk['words']));

        // The one who talks sees the words.
        $session->update(['stage' => PairPracticeSession::STAGE_SPEAKING, 'joined_at' => now(), 'stage_ends_at' => now()->addMinutes(6), 'deck' => [...$session->deck, 'speaking' => [$talks->first()]]]);
        $this->actingAs($session->speaker());
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->assertSee('Talk for a minute using these words')
            ->assertSee($talks->first()['words'][0]);
    }

    public function test_the_end_saves_a_missed_sentence_to_the_error_log_and_unused_target_words_to_my_words(): void
    {
        $mission = $this->makeMission();
        $session = PairPracticeSession::factory()->create();
        [$first, $second] = $session->players();
        MissionRun::findOrStart($first, $mission);
        $secondRun = MissionRun::findOrStart($second, $mission);

        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session])->call('judge', true, 0);
        $this->actingAs($first);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])->call('judge', false, 1);
        $this->actingAs($second);
        Livewire::test('pair-practice.session', ['session' => $session->refresh()])
            ->set('heardWords', ['wake up'])
            ->call('nextQuestion', 2);

        $this->assertTrue($session->refresh()->isFinished());
        $this->assertDatabaseHas('error_log_items', ['mission_run_id' => $secondRun->id, 'error' => 'She go to work at eight.', 'correction' => 'She goes to work at eight.']);
        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $first->id, 'word' => 'usually']);
        $this->assertDatabaseMissing('vocabulary_words', ['learner_id' => $first->id, 'word' => 'wake up']);

        Livewire::test('pair-practice.session', ['session' => $session])
            ->assertSee('For your review (saved)')
            ->assertSee('She goes to work at eight.')
            ->assertDontSee('usually');
    }

    public function test_another_round_draws_from_the_same_days_and_the_friend_can_join_it_from_the_result(): void
    {
        Notification::fake();
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1, 2]);
        $this->finishDays($friend, $mission, [1, 2]);
        $round = app(PairPractice::class)->start($me, $friend, ['M01-1']);
        $round->update(['joined_at' => now(), 'stage' => PairPracticeSession::STAGE_DONE, 'finished_at' => now()]);
        $round->refresh();

        $this->actingAs($me);
        $component = Livewire::test('pair-practice.session', ['session' => $round])->call('anotherRound');

        $next = PairPracticeSession::whereKeyNot($round->id)->sole();
        $component->assertRedirect(route('pair-practice.show', $next));
        $this->assertSame(['M01-1'], $next->days);
        Notification::assertSentTo($friend, PairPracticeInvite::class);

        $this->actingAs($friend);
        Livewire::test('pair-practice.session', ['session' => $round])
            ->assertSee('Join '.$me->name.'’s next round')
            ->call('anotherRound')
            ->assertRedirect(route('pair-practice.show', $next));
        $this->assertSame(2, PairPracticeSession::count());
    }

    public function test_a_finished_mission_day_links_to_practising_it_with_a_friend(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $this->finishDays($me, $mission, [1]);
        $this->actingAs($me);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'overview'])
            ->assertDontSee('Practice this day with a friend');

        $friend = $this->makeMutualFriend($me);
        $this->finishDays($friend, $mission, [1]);

        Livewire::test('missions.runner', ['mission' => $mission, 'step' => 'overview'])
            ->assertSee('Practice this day with a friend')
            ->assertSeeHtml(e(route('pair-practice.index', ['day' => 'M01-1'])));

        Livewire::withQueryParams(['day' => 'M01-1'])->test('pair-practice.index')
            ->assertSee('M01 · Day 1 · pick a friend')
            ->assertSeeHtml("mode: 'choose'");
    }

    public function test_each_card_and_the_result_show_how_often_the_pair_practised_together(): void
    {
        $mission = $this->makeMission();
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);
        $this->finishDays($me, $mission, [1]);
        $this->finishDays($friend, $mission, [1]);
        $done = fn (int $daysAgo) => PairPracticeSession::factory()->create([
            'user_a_id' => $me->id,
            'user_b_id' => $friend->id,
            'stage' => PairPracticeSession::STAGE_DONE,
            'finished_at' => now()->subDays($daysAgo),
            'score_a' => 3,
            'score_b' => 2,
        ]);
        $done(5);
        $done(4);

        $this->actingAs($me);

        Livewire::test('pair-practice.index')
            ->assertSee('2× together')
            ->assertSee('4 days without practicing together')
            ->assertDontSee('🔥');

        $done(1);
        $last = $done(0);

        Livewire::test('pair-practice.index')
            ->assertSee('4× together')
            ->assertSee('🔥 2 days')
            ->assertDontSee('days without practicing together')
            ->assertSeeText('Next: M01 · Day 1');

        Livewire::test('pair-practice.session', ['session' => $last])
            ->assertSee('4th time together')
            ->assertSee('🔥 2 days');
    }
}
