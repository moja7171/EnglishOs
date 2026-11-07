<?php

namespace Tests\Feature;

use App\Models\AskAnswerItem;
use App\Models\AskAnswerProgress;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\NotebookEntry;
use App\Models\PairGame;
use App\Models\User;
use App\Services\AiPractices;
use App\Services\AskAnswerDeck;
use App\Services\GroqClient;
use App\Services\PairGames;
use Database\Seeders\AskAnswerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class AskAnswerGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(AskAnswerSeeder::class);
    }

    private function makeMutualFriend(User $me): User
    {
        $friend = User::factory()->create();
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    /**
     * M11 with its first day finished by both friends.
     *
     * @return array{0: User, 1: User}
     */
    private function twoFriendsWhoFinishedM11Day1(): array
    {
        $mission = Mission::create([
            'code' => 'M11',
            'title' => 'Work',
            'module' => 'Work',
            'outcome' => 'I can talk about work.',
            'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'vocabulary_builder_1', 'words' => []]]]],
        ]);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);

        foreach ([$me, $friend] as $user) {
            $run = MissionRun::findOrStart($user, $mission);
            Evidence::create(['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder_1', 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);
        }

        return [$me, $friend];
    }

    private function startedGame(User $me, User $friend, int $minutes = 5): PairGame
    {
        $game = app(PairGames::class)->start($me, $friend, PairGame::GAME_ASK, $minutes);
        $game->joinWithLock($friend);
        $game->refresh();
        $game->startTurn($game->startedBy);

        return $game->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function ok(bool $extra = false): array
    {
        return ['status' => 'ok', 'extra' => $extra, 'correct' => 'x', 'why' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function wrong(string $correct = 'Say this.'): array
    {
        return ['status' => 'wrong', 'extra' => false, 'correct' => $correct, 'why' => 'دلیل'];
    }

    public function test_a_game_draws_four_plain_rounds_then_four_follow_ups_from_the_finished_days(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();

        $game = app(PairGames::class)->start($me, $friend, PairGame::GAME_ASK, 5);

        $this->assertSame(8, $game->plannedTurns());
        $this->assertSame(['simple', 'simple', 'simple', 'simple', 'followup', 'followup', 'followup', 'followup'], collect($game->deck)->pluck('kind')->all());
        $this->assertSame(8, collect($game->deck)->pluck('id')->unique()->count());
        $this->assertEmpty(collect($game->deck)->pluck('source')->reject(fn (string $source) => $source === 'M11 · Day 1')->all());
        $this->assertSame(['q' => 1, 'a' => 1], $game->askLevels($me));
        $this->assertSame(16, app(PairGames::class)->start($friend, User::factory()->create(), PairGame::GAME_ASK, 10)?->plannedTurns() ?? 16);
    }

    public function test_the_game_has_nothing_to_draw_for_missions_without_situations(): void
    {
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);

        $this->assertFalse(app(AskAnswerDeck::class)->available($me, $friend));
        $this->assertNull(app(PairGames::class)->start($me, $friend, PairGame::GAME_ASK, 5));
    }

    public function test_a_round_goes_question_then_answer_and_then_the_friend_asks(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);

        $this->assertSame(PairGame::PHASE_QUESTION, $game->phase());
        $this->assertTrue($game->askSpeaker()->is($game->startedBy));

        // Only the asker speaks in the question step.
        $game->recordSpoken($game->partnerFor($game->startedBy), 'Where do you work?', $this->ok());
        $this->assertSame(PairGame::PHASE_QUESTION, $game->refresh()->phase());

        $game->recordSpoken($game->startedBy, 'Where do you work?', $this->ok());
        $game->refresh();
        $this->assertSame(PairGame::PHASE_ANSWER, $game->phase());
        $this->assertTrue($game->askSpeaker()->is($game->partnerFor($game->startedBy)));

        $game->recordSpoken($game->partnerFor($game->startedBy), 'I work for a company.', $this->ok());
        $game->refresh();

        $this->assertSame(PairGame::STAGE_READY, $game->stage);
        $this->assertSame(4, $game->score);
        $this->assertSame(1, $game->creditFor($game->startedBy));
        $this->assertTrue($game->explainer()->is($game->partnerFor($game->startedBy)));
    }

    public function test_a_miss_shows_the_right_sentence_and_a_fix_earns_half(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $asker = $game->startedBy;

        $game->recordSpoken($asker, 'Where you work?', $this->wrong('Where do you work?'));
        $game->refresh();

        $this->assertSame(PairGame::PHASE_QUESTION, $game->phase());
        $this->assertSame(1, $game->askStep()['tries']);
        $this->assertSame('Where do you work?', $game->askLevelToCheck()['en']);

        $game->recordSpoken($asker, 'Where do you work?', $this->ok());
        $game->refresh();

        $this->assertSame(PairGame::PHASE_ANSWER, $game->phase());
        $this->assertSame('fixed', $game->currentTurn()['current']['qa']['question']['status']);
        $this->assertSame(PairGame::POINTS_WITH_LETTER, $game->currentTurn()['current']['qa']['question']['points']);
    }

    public function test_a_second_miss_or_a_skip_passes_on_and_two_unchecked_tries_score_nothing(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $asker = $game->startedBy;
        $answerer = $game->partnerFor($asker);

        $game->recordSpoken($asker, 'a', $this->wrong());
        $game->refresh()->recordSpoken($asker, 'b', $this->wrong());
        $game->refresh();
        $this->assertSame('passed', $game->currentTurn()['current']['qa']['question']['status']);
        $this->assertSame(PairGame::PHASE_ANSWER, $game->phase());

        $game->passStep($answerer);
        $game->refresh();
        $this->assertSame(PairGame::STAGE_READY, $game->stage);
        $this->assertSame(0, $game->score);
        $this->assertSame(0, $game->askCreditFor($asker) + $game->askCreditFor($answerer));

        $game->startTurn($game->explainer());
        $game->refresh();
        $game->recordSpoken($game->explainer(), 'a', ['status' => 'unchecked', 'extra' => false, 'correct' => 'x', 'why' => null]);
        $game->refresh()->recordSpoken($game->explainer(), 'a', ['status' => 'unchecked', 'extra' => false, 'correct' => 'x', 'why' => null]);

        $this->assertSame('unchecked', $game->refresh()->currentTurn()['current']['qa']['question']['status']);
        $this->assertSame(0, $game->currentTurn()['current']['qa']['question']['points']);
    }

    public function test_the_extra_detail_earns_a_half_point_more(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);

        $game->recordSpoken($game->startedBy, 'x', $this->ok(extra: true));

        $this->assertSame(PairGame::POINTS_WORD + PairGame::POINTS_EXTRA, $game->refresh()->currentTurn()['current']['qa']['question']['points']);
    }

    public function test_each_player_picks_their_own_levels_and_the_speaker_sees_their_own_level(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $asker = $game->startedBy;
        $other = $game->partnerFor($asker);

        $game->setAskLevel($asker, 'q', 3);
        $game->setAskLevel($other, 'a', 2);
        $game->setAskLevel($other, 'q', 9);
        $game->refresh();

        $this->assertSame(['q' => 3, 'a' => 1], $game->askLevels($asker));
        $this->assertSame(['q' => 3, 'a' => 2], $game->askLevels($other));
        $this->assertSame($game->currentCard()['questions'][3]['en'], $game->askLevel()['en']);

        $game->recordSpoken($asker, 'x', $this->ok());
        $this->assertSame($game->refresh()->currentCard()['answers'][2]['en'], $game->askLevel()['en']);
    }

    public function test_a_finished_game_remembers_what_each_friend_played_and_missed(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $itemId = $game->deck[0]['id'];

        $game->recordSpoken($game->startedBy, 'x', $this->ok());
        $game->refresh()->passStep($game->partnerFor($game->startedBy));
        $game->refresh()->endEarly($me);

        $asker = AskAnswerProgress::where('user_id', $game->startedBy->id)->where('ask_answer_item_id', $itemId)->first();
        $answerer = AskAnswerProgress::where('user_id', $game->partnerFor($game->startedBy)->id)->where('ask_answer_item_id', $itemId)->first();

        $this->assertSame(1, $asker->times_seen);
        $this->assertFalse($asker->missed);
        $this->assertTrue($answerer->missed);
    }

    public function test_the_next_game_takes_situations_neither_friend_has_played_first(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $first = $this->startedGame($me, $friend);
        $played = collect($first->deck)->pluck('id');

        foreach ($played as $itemId) {
            foreach ([$me, $friend] as $user) {
                AskAnswerProgress::create(['user_id' => $user->id, 'ask_answer_item_id' => $itemId, 'times_seen' => 1, 'last_seen_at' => now()]);
            }
        }

        $first->endEarly($me);
        $second = app(PairGames::class)->start($me, $friend, PairGame::GAME_ASK, 5);

        // M11 day 1 has 5 plain and 5 follow-up situations; the unplayed one of each leads.
        $unplayedPlain = AskAnswerItem::where('day', 1)->where('kind', 'simple')->whereNotIn('id', $played)->value('id');
        $unplayedFollowUp = AskAnswerItem::where('day', 1)->where('kind', 'followup')->whereNotIn('id', $played)->value('id');

        $this->assertSame($unplayedPlain, $second->deck[0]['id']);
        $this->assertSame($unplayedFollowUp, $second->deck[4]['id']);
    }

    public function test_a_spoken_wrong_sentence_is_checked_on_the_page_and_kept_in_the_notebook(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $asker = $game->startedBy;
        $item = AskAnswerItem::find($game->deck[0]['id']);

        $this->mock(GroqClient::class, fn (MockInterface $mock) => $mock->shouldReceive('transcribe')->andReturn($item->level('questions', 1)['wrong'][0]['text']));

        $this->actingAs($asker);
        Livewire::test('games.play', ['pairGame' => $game])
            ->set('audioFile', UploadedFile::fake()->create('say.webm', 10, 'audio/webm'))
            ->call('submitAskAnswer')
            ->assertSet('askError', null);

        $this->assertSame(1, $game->refresh()->askStep()['tries']);
        $this->assertSame($item->level('questions', 1)['en'], $game->askStep()['correct']);
        $this->assertSame(1, NotebookEntry::where('learner_id', $asker->id)->where('kind', NotebookEntry::KIND_MISTAKE)->count());
    }

    public function test_a_right_sentence_on_the_page_moves_to_the_answer(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $item = AskAnswerItem::find($game->deck[0]['id']);

        $this->mock(GroqClient::class, fn (MockInterface $mock) => $mock->shouldReceive('transcribe')->andReturn($item->level('questions', 1)['en']));

        $this->actingAs($game->startedBy);
        Livewire::test('games.play', ['pairGame' => $game])
            ->set('audioFile', UploadedFile::fake()->create('say.webm', 10, 'audio/webm'))
            ->call('submitAskAnswer');

        $this->assertSame(PairGame::PHASE_ANSWER, $game->refresh()->phase());
    }

    public function test_only_the_speaker_can_send_a_recording_and_the_listener_sees_the_english(): void
    {
        [$me, $friend] = $this->twoFriendsWhoFinishedM11Day1();
        $game = $this->startedGame($me, $friend);
        $listener = $game->partnerFor($game->startedBy);

        $this->mock(GroqClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('transcribe'));

        $this->actingAs($listener);
        Livewire::test('games.play', ['pairGame' => $game])
            ->assertSee($game->askLevel()['en'])
            ->assertDontSee($game->askLevel()['fa'])
            ->set('audioFile', UploadedFile::fake()->create('say.webm', 10, 'audio/webm'))
            ->call('submitAskAnswer');

        $this->assertSame(PairGame::PHASE_QUESTION, $game->refresh()->phase());

        $this->actingAs($game->startedBy);
        Livewire::test('games.play', ['pairGame' => $game])
            ->assertSee($game->askLevel()['fa'])
            ->assertDontSee('Tap to check your friend');
    }

    public function test_the_setup_page_remembers_levels_and_says_when_there_is_nothing_to_play(): void
    {
        $me = User::factory()->create();
        $this->makeMutualFriend($me);

        $this->actingAs($me);
        Livewire::test('games.setup', ['game' => PairGame::GAME_ASK])
            ->assertSee('No situations for your missions yet')
            ->call('setAskLevel', 'q', 3)
            ->call('setAskLevel', 'a', 2);

        $this->assertSame(3, $me->refresh()->ask_question_level);
        $this->assertSame(2, $me->ask_answer_level);
    }

    public function test_teddy_plays_it_alone_asking_on_odd_rounds_and_answering_on_even_ones(): void
    {
        [$me] = $this->twoFriendsWhoFinishedM11Day1();

        $practice = app(AiPractices::class)->start($me, PairGame::GAME_ASK);

        $this->assertSame(PairGame::GAME_ASK, $practice->game());
        $this->assertCount(8, $practice->cards());
        $this->assertTrue($practice->isLearnersGameTurn());
        $this->assertSame($practice->currentCard()['answers'][1]['en'], $practice->askTeddyLine());
        $this->assertSame($practice->currentCard()['questions'][1]['en'], $practice->askTarget()['en']);

        $practice->update(['position' => 1]);
        $practice->refresh();

        $this->assertFalse($practice->isLearnersGameTurn());
        $this->assertSame($practice->currentCard()['questions'][1]['en'], $practice->askTeddyLine());
        $this->assertSame($practice->currentCard()['answers'][1]['en'], $practice->askTarget()['en']);
    }

    public function test_with_teddy_a_right_sentence_scores_and_a_miss_is_kept_in_the_notebook_and_repeated(): void
    {
        [$me] = $this->twoFriendsWhoFinishedM11Day1();
        $practice = app(AiPractices::class)->start($me, PairGame::GAME_ASK);
        $item = AskAnswerItem::find($practice->currentCard()['id']);
        $wrong = $item->level('questions', 1)['wrong'][0]['text'];
        $right = $item->level('questions', 1)['en'];

        $heard = [$wrong, $right];
        $this->mock(GroqClient::class, function (MockInterface $mock) use (&$heard) {
            $mock->shouldReceive('transcribe')->andReturnUsing(function () use (&$heard) {
                return array_shift($heard);
            });
        });

        $this->actingAs($me);
        $page = Livewire::test('ai-practice.session', ['practice' => $practice]);
        $page->set('audioFile', UploadedFile::fake()->create('answer.webm', 10, 'audio/webm'))->call('submitAnswer');

        $practice->refresh();
        $this->assertSame(1, $practice->askStep()['tries']);
        $this->assertNull($practice->askStep()['status']);
        $this->assertSame($right, $practice->askTargetToCheck()['en']);
        $this->assertSame(1, NotebookEntry::where('learner_id', $me->id)->count());

        $page->set('audioFile', UploadedFile::fake()->create('answer.webm', 10, 'audio/webm'))->call('submitAnswer');

        $practice->refresh();
        $this->assertSame('fixed', $practice->askStep()['status']);
        $this->assertSame(0, $practice->score);
        $this->assertSame(1, $practice->answered);

        $page->call('next');
        $this->assertSame(1, $practice->refresh()->position);
        $this->assertNull($practice->current);
    }

    public function test_with_teddy_skipping_shows_the_right_sentence_and_levels_are_remembered(): void
    {
        [$me] = $this->twoFriendsWhoFinishedM11Day1();
        $practice = app(AiPractices::class)->start($me, PairGame::GAME_ASK);

        $this->actingAs($me);
        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->call('setAskLevel', 'q', 3)
            ->call('passAskStep');

        $practice->refresh();
        $this->assertSame('passed', $practice->askStep()['status']);
        $this->assertSame($practice->currentCard()['questions'][3]['en'], $practice->askStep()['correct']);
        $this->assertSame(3, $me->refresh()->ask_question_level);
    }
}
