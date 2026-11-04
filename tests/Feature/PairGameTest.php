<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use App\Models\VoiceFeedback;
use App\Notifications\PairGameInvite;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\JustAMinuteDeck;
use App\Services\RolePlayDeck;
use App\Services\StoryChainDeck;
use App\Services\TabooDeck;
use App\Services\TwoTruthsDeck;
use App\Services\WhoAmIDeck;
use App\Services\WouldYouRatherDeck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PairGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Bonus words for notebook words are asked of the AI after the response.
        Http::fake();
    }

    private function makeMission(string $code, array $words): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'I can talk.',
            'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [
                ['key' => 'vocabulary_builder_'.$code, 'words' => collect($words)->map(fn (string $word) => ['phrase' => $word, 'meaning_fa' => 'معنی '.$word])->all()],
            ]]],
        ]);
    }

    private function makeMutualFriend(User $me): User
    {
        $friend = User::factory()->create();
        $me->follow($friend);
        $friend->acceptFollowRequest($me);

        return $friend;
    }

    private function finishDayOne(User $user, Mission $mission): void
    {
        $run = MissionRun::findOrStart($user, $mission);

        foreach ($mission->phases[0]['steps'] as $step) {
            Evidence::create(['mission_run_id' => $run->id, 'phase' => $step['key'], 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);
        }
    }

    /**
     * A joined game whose first turn (explained by user A) is running.
     */
    private function playingGame(array $attributes = []): PairGame
    {
        $pairGame = PairGame::factory()->create($attributes);
        $pairGame->startTurn($pairGame->startedBy);

        return $pairGame->refresh();
    }

    public function test_the_games_page_shows_every_game_and_each_one_opens(): void
    {
        $this->actingAs(User::factory()->create());

        $page = $this->get(route('games.index'))->assertOk()->assertDontSee('Soon');

        foreach (PairGame::GAMES as $game) {
            $page->assertSee(PairGame::info($game)['name'])->assertSee(route('games.setup', $game), false);
            $this->get(route('games.setup', $game))->assertOk();
        }

        $this->get(route('games.setup', 'picture-match'))->assertNotFound();
    }

    public function test_inviting_a_friend_creates_a_waiting_game_and_notifies_them(): void
    {
        Notification::fake();
        $this->makeMission('M01', ['wake up', 'commute']);
        $me = User::factory()->create();
        $friend = $this->makeMutualFriend($me);

        $this->actingAs($me);
        Livewire::test('games.setup', ['game' => 'taboo'])
            ->assertSee('Invite '.$friend->name.' · Play')
            ->set('minutes', 10)
            ->call('play')
            ->assertRedirect(route('games.play', PairGame::sole()));

        $pairGame = PairGame::sole();
        $this->assertTrue($pairGame->isWaiting());
        $this->assertSame(10, $pairGame->minutes);
        // Two newcomers with no finished day yet play M01 Day 1's words.
        $this->assertEqualsCanonicalizing(['wake up', 'commute'], collect($pairGame->deck)->pluck('word')->all());
        Notification::assertSentTo($friend, PairGameInvite::class);
    }

    public function test_only_a_mutual_friend_can_be_invited(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('games.setup', ['game' => 'taboo'])
            ->set('friend', $stranger->id)
            ->call('play')
            ->assertForbidden();

        $this->assertSame(0, PairGame::count());
    }

    public function test_beginners_get_longer_turns_and_free_skips_and_the_newer_learner_sets_the_level(): void
    {
        $m02 = $this->makeMission('M02', ['commute']);
        $m06 = $this->makeMission('M06', ['fluent']);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $deck = app(TabooDeck::class);
        MissionRun::findOrStart($me, $m06);
        MissionRun::findOrStart($friend, $m02);

        $beginner = $deck->rulesFor($me, $friend, 5);
        $this->assertSame(TabooDeck::BEGINNER_TURN_SECONDS, $beginner['turn_seconds']);
        $this->assertNull($beginner['skips']);
        $this->assertFalse($beginner['bonus']);
        $this->assertSame(0, $beginner['turns'] % 2);

        $other = User::factory()->create();
        MissionRun::findOrStart($other, $m06);
        $later = $deck->rulesFor($me, $other, 5);
        $this->assertSame(TabooDeck::TURN_SECONDS, $later['turn_seconds']);
        $this->assertSame(TabooDeck::SKIPS_PER_TURN, $later['skips']);
        $this->assertTrue($later['bonus']);
        $this->assertGreaterThan($later['turns'], $deck->rulesFor($me, $other, 10)['turns']);
    }

    public function test_cards_come_from_shared_mission_days_and_both_notebooks_with_written_bonus_words(): void
    {
        $mission = $this->makeMission('M06', ['fluent', 'accent']);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->finishDayOne($me, $mission);
        $this->finishDayOne($friend, $mission);
        $me->vocabularyWords()->create(['word' => 'umbrella', 'next_review_at' => now()]);
        $friend->vocabularyWords()->create(['word' => 'a whole sentence that is far too long for a card', 'next_review_at' => now()]);

        $cards = collect(app(TabooDeck::class)->cardsFor($me, $friend, ['turns' => 2, 'bonus' => true]))->keyBy('word');

        $this->assertEqualsCanonicalizing(['fluent', 'accent', 'umbrella'], $cards->keys()->all());
        $this->assertSame(config('taboo_bonus.fluent'), $cards['fluent']['bonus']);
        $this->assertSame('M06 · Mission M06', $cards['fluent']['source']);
        $this->assertSame('Notebook', $cards['umbrella']['source']);
    }

    public function test_the_invited_friend_joins_in_one_tap_from_the_invite(): void
    {
        $pairGame = PairGame::factory()->waiting()->create();
        [$starter, $friend] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];
        $friend->notify(new PairGameInvite($pairGame, $starter));

        $this->actingAs($starter)->get(route('games.play', ['pairGame' => $pairGame, 'join' => 1]))->assertOk();
        $this->assertTrue($pairGame->refresh()->isWaiting());

        $this->actingAs($friend)->get(route('games.play', ['pairGame' => $pairGame, 'join' => 1]))->assertOk();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->refresh()->stage);
        $this->assertSame(0, $friend->unreadNotifications()->count());

        $this->actingAs(User::factory()->create())->get(route('games.play', $pairGame))->assertForbidden();
    }

    public function test_only_the_explainer_sees_the_card_and_scores_it(): void
    {
        $pairGame = $this->playingGame();
        [$explainer, $guesser] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($guesser);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('Only '.$explainer->name.' sees the card')
            ->assertDontSee('breakfast')
            ->call('got', 0);
        $this->assertSame(0, $pairGame->refresh()->score);

        $this->actingAs($explainer);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('breakfast')
            ->assertSee('Don’t say: morning · eat')
            ->call('got', 0)
            ->call('got', 0);

        // One word plus its kept bonus; the stale second tap changed nothing.
        $this->assertSame(PairGame::POINTS_WORD + PairGame::POINTS_BONUS, $pairGame->refresh()->score);
        $this->assertSame('kitchen', $pairGame->currentCard()['word']);
    }

    public function test_the_first_letter_halves_a_word_and_a_bonus_word_said_loses_the_bonus(): void
    {
        $pairGame = $this->playingGame();
        $explainer = $pairGame->startedBy;

        $this->actingAs($explainer);
        $component = Livewire::test('games.play', ['pairGame' => $pairGame])
            ->call('showLetter', 0)
            ->call('bonusSaid', 0);

        $this->actingAs($pairGame->partnerFor($explainer));
        Livewire::test('games.play', ['pairGame' => $pairGame->refresh()])->assertSee('b _ _ _ _ _ _ _ _');

        $this->actingAs($explainer);
        $component->call('got', 0);

        $this->assertSame(PairGame::POINTS_WITH_LETTER, $pairGame->refresh()->score);
        $this->assertSame('½', PairGame::formatPoints($pairGame->score));
    }

    public function test_skips_run_out_after_the_turns_limit(): void
    {
        $pairGame = $this->playingGame();
        $explainer = $pairGame->startedBy;

        foreach (range(0, 2) as $seen) {
            $pairGame->refresh()->scoreCard($explainer, PairGame::RESULT_SKIP, $seen);
        }

        $this->assertCount(2, $pairGame->refresh()->currentTurn()['cards']);
        $this->assertSame(0, $pairGame->skipsLeft());
    }

    public function test_time_up_ends_the_turn_and_the_two_swap_until_the_last_turn_ends_the_game(): void
    {
        $pairGame = $this->playingGame(['rules' => ['turn_seconds' => 60, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 3, 'level' => 2]]);
        [$first, $second] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $pairGame->timeUp(0);
        $this->assertTrue($pairGame->refresh()->isPlaying(), 'An early call closes nothing.');

        $this->travel(61)->seconds();
        $pairGame->timeUp(0);
        $pairGame->refresh();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertTrue($pairGame->explainer()->is($second));
        $this->assertSame(['breakfast'], $pairGame->missedWords());

        $pairGame->startTurn($first);
        $this->assertFalse($pairGame->refresh()->isPlaying(), 'Only the next explainer starts the turn.');
        $pairGame->startTurn($second);
        $this->assertSame('kitchen', $pairGame->refresh()->currentCard()['word']);
        $pairGame->scoreCard($second, PairGame::RESULT_GOT, 0);

        $this->travel(61)->seconds();
        $pairGame->refresh()->timeUp(1);

        $this->assertTrue($pairGame->refresh()->isFinished());
        $this->assertSame(1, $pairGame->guessedWhileExplaining($second));
        $this->assertSame(0, $pairGame->guessedWhileExplaining($first));
    }

    public function test_the_result_shows_the_team_score_a_new_record_and_the_missed_words(): void
    {
        $pairGame = $this->playingGame();
        $explainer = $pairGame->startedBy;
        PairGame::factory()->create([
            'user_a_id' => $pairGame->user_a_id,
            'user_b_id' => $pairGame->user_b_id,
            'started_by_id' => $pairGame->user_a_id,
            'stage' => PairGame::STAGE_DONE,
            'score' => 2,
            'finished_at' => now()->subDay(),
        ]);

        $pairGame->scoreCard($explainer, PairGame::RESULT_GOT, 0);
        $pairGame->refresh()->scoreCard($explainer, PairGame::RESULT_SKIP, 1);
        $pairGame->refresh()->endEarly($explainer);

        $this->actingAs($explainer);
        Livewire::test('games.play', ['pairGame' => $pairGame->refresh()])
            ->assertSee('NEW RECORD!')
            ->assertSee('points together · best was 1')
            ->assertSee('Missed words')
            ->assertSee('kitchen');
    }

    public function test_a_game_ended_before_any_card_does_not_count(): void
    {
        $pairGame = $this->playingGame();

        $pairGame->endEarly($pairGame->startedBy);

        $this->assertTrue($pairGame->refresh()->isCancelled());
        $this->assertNull($pairGame->previousBest());
    }

    public function test_a_live_game_invite_stays_up_and_shows_on_the_friends_page_until_joined(): void
    {
        $pairGame = PairGame::factory()->waiting()->create();
        [$starter, $friend] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];
        $friend->notify(new PairGameInvite($pairGame, $starter));

        $this->actingAs($friend);
        Livewire::test('notifications.bell')
            ->call('checkNew')
            ->assertDispatched('notification-banner', fn (string $event, array $params) => $params['items'][0]['urgent'] === true);
        Livewire::test('friends.index')
            ->assertSee($starter->name.' is waiting for you')
            ->assertSeeHtml(e(route('games.play', ['pairGame' => $pairGame, 'join' => 1])));

        $pairGame->join($friend);

        Livewire::test('notifications.bell')->call('checkNew')->assertNotDispatched('notification-banner');
        $this->assertSame(0, $friend->unreadNotifications()->count());
    }

    public function test_my_explaining_turn_is_kept_with_its_transcript_and_the_words_of_the_turn(): void
    {
        Storage::fake('local');
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('You eat it in morning before you go work.'));
        $pairGame = $this->playingGame();
        [$explainer, $guesser] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];
        $pairGame->scoreCard($explainer, PairGame::RESULT_GOT, 0);
        $clip = fn () => UploadedFile::fake()->create('turn-0.webm', 40, 'audio/webm');

        // The guesser's phone never keeps a piece of a turn it did not explain.
        $this->actingAs($guesser);
        Livewire::test('games.play', ['pairGame' => $pairGame])->set('voiceClip', $clip())->call('saveVoiceClip', 'turn-0');
        $this->assertNull(VoiceFeedback::existingFor($guesser, $pairGame));

        $this->actingAs($explainer);
        $component = Livewire::test('games.play', ['pairGame' => $pairGame]);
        $component->set('voiceClip', $clip())->call('saveVoiceClip', 'turn-0');
        // Sent twice on a weak connection: kept once.
        $component->set('voiceClip', $clip())->call('saveVoiceClip', 'turn-0');

        $voice = VoiceFeedback::existingFor($explainer, $pairGame);
        $this->assertCount(1, $voice->clips);
        $this->assertSame('Turn 1 · you explained: breakfast', $voice->clips[0]['label']);
        $this->assertSame('You eat it in morning before you go work.', $voice->clips[0]['transcript']);
        Storage::disk('local')->assertExists($voice->clips[0]['path']);

        $this->actingAs($guesser)->get(route('voice-feedback.clip', ['voiceFeedback' => $voice, 'clip' => 0]))->assertForbidden();
        $this->actingAs($explainer)->get(route('voice-feedback.clip', ['voiceFeedback' => $voice, 'clip' => 0]))->assertOk();
    }

    public function test_after_the_game_my_sentences_get_feedback_once(): void
    {
        $pairGame = PairGame::factory()->create(['stage' => PairGame::STAGE_DONE, 'finished_at' => now(), 'score' => 2]);
        $me = $pairGame->startedBy;
        $voice = VoiceFeedback::for($me, $pairGame);
        $voice->update(['clips' => [['part' => 'turn-0', 'label' => 'Turn 1 · you explained: breakfast', 'path' => 'x.webm', 'transcript' => 'You eat it in morning before you go work.']]]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn(json_encode([
            'summary' => 'خوب توضیح دادی.',
            'severity' => 'minor',
            'sentences' => [['original' => 'You eat it in morning before you go work.', 'status' => 'fix', 'corrected' => 'You eat it in the morning before you go to work.', 'why' => 'the morning · go to work', 'answer' => 1]],
            'positives' => [],
            'negatives' => [],
        ])));

        $this->actingAs($me);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('Checking your sentences…')
            ->call('checkMyVoice')
            ->call('checkMyVoice')
            ->assertSee('Your sentences')
            ->assertSee('You eat it in the morning before you go to work.');

        $this->assertSame(VoiceFeedback::STATUS_READY, $voice->refresh()->status);
    }

    public function test_too_little_said_gives_no_feedback_call(): void
    {
        $pairGame = PairGame::factory()->create(['stage' => PairGame::STAGE_DONE, 'finished_at' => now()]);
        $me = $pairGame->startedBy;
        VoiceFeedback::for($me, $pairGame)->update(['clips' => [['part' => 'turn-0', 'label' => 'Turn 1', 'path' => 'x.webm', 'transcript' => 'yes no']]]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->actingAs($me);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->call('checkMyVoice')
            ->assertSee('Not enough was recorded to check this time.');
    }

    public function test_the_feedback_switch_is_on_by_default_and_remembered(): void
    {
        $me = User::factory()->create();
        $this->assertTrue($me->refresh()->game_voice_feedback);

        $this->actingAs($me);
        Livewire::test('games.setup', ['game' => 'taboo'])->call('setVoiceFeedback', false);

        $this->assertFalse($me->refresh()->game_voice_feedback);
    }

    /**
     * A joined Who am I game whose first turn is running: user A holds
     * "cat", user B asks.
     */
    private function whoAmIGame(): PairGame
    {
        return $this->playingGame([
            'game' => PairGame::GAME_WHO,
            'rules' => ['turn_seconds' => 90, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 2, 'show_category' => true],
            'deck' => [
                ['word' => 'cat', 'category' => 'an animal', 'source' => 'An animal', 'bonus' => [], 'key' => 'who:cat'],
                ['word' => 'teacher', 'category' => 'a job', 'source' => 'A job', 'bonus' => [], 'key' => 'who:teacher'],
            ],
        ]);
    }

    public function test_who_am_i_secrets_open_with_the_missions_that_teach_them(): void
    {
        $m02 = $this->makeMission('M02', ['commute']);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        MissionRun::findOrStart($me, $m02);
        MissionRun::findOrStart($friend, $this->makeMission('M06', ['fluent']));
        $deck = app(WhoAmIDeck::class);

        $rules = $deck->rulesFor($me, $friend, 5);
        $words = collect($deck->cardsFor($me, $friend, ['turns' => 40, 'level' => $rules['level']]))->pluck('word');

        $this->assertTrue($rules['show_category']);
        $this->assertContains('airport', $words);
        $this->assertNotContains('teacher', $words, 'Jobs come with M03.');
        $this->assertNotContains('pizza', $words, 'Food comes with M04.');
    }

    public function test_only_the_holder_sees_the_secret_and_answers_and_the_guesser_sees_the_answer(): void
    {
        $pairGame = $this->whoAmIGame();
        [$holder, $guesser] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($guesser);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee($holder->name.' holds the secret')
            ->assertSee('It’s an animal')
            ->assertDontSee('cat')
            ->call('answer', 'yes', 0);
        $this->assertSame(0, $pairGame->refresh()->questionsAsked());

        $this->actingAs($holder);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('cat')
            ->call('answer', 'no', 0)
            ->call('answer', 'no', 0);
        $this->assertSame(1, $pairGame->refresh()->questionsAsked());

        $this->actingAs($guesser);
        Livewire::test('games.play', ['pairGame' => $pairGame])->assertSee('QUESTIONS 1 / 10');
    }

    public function test_a_quick_guess_earns_the_bonus_and_ends_the_turn_and_the_two_swap(): void
    {
        $pairGame = $this->whoAmIGame();
        [$holder, $guesser] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $pairGame->answerQuestion($holder, 'yes', 0);
        $pairGame->refresh()->scoreCard($holder, PairGame::RESULT_GOT, 0);

        $pairGame->refresh();
        $this->assertSame(PairGame::POINTS_WORD + PairGame::POINTS_BONUS, $pairGame->score);
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertTrue($pairGame->explainer()->is($guesser));
        $this->assertSame(1, $pairGame->creditFor($guesser));

        $pairGame->startTurn($guesser);
        $this->assertSame('teacher', $pairGame->refresh()->currentCard()['word']);
        foreach (range(0, PairGame::QUICK_GUESS_QUESTIONS) as $asked) {
            $pairGame->refresh()->answerQuestion($guesser, 'no', $asked);
        }
        $pairGame->refresh()->scoreCard($guesser, PairGame::RESULT_GOT, 0);

        $this->assertTrue($pairGame->refresh()->isFinished());
        $this->assertSame(2 * PairGame::POINTS_WORD + PairGame::POINTS_BONUS, $pairGame->score);
    }

    public function test_ten_questions_without_a_guess_end_the_turn(): void
    {
        $pairGame = $this->whoAmIGame();
        $holder = $pairGame->startedBy;

        foreach (range(0, PairGame::MAX_QUESTIONS - 1) as $asked) {
            $pairGame->refresh()->answerQuestion($holder, 'no', $asked);
        }

        $pairGame->refresh();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(['cat'], $pairGame->missedWords());
        $this->assertSame(0, $pairGame->score);
    }

    public function test_in_who_am_i_the_guessers_questions_are_recorded_not_the_holders_answers(): void
    {
        Storage::fake('local');
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->once()->andReturn('Am I an animal? Do I live in a house?'));
        $pairGame = $this->whoAmIGame();
        [$holder, $guesser] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];
        $pairGame->scoreCard($holder, PairGame::RESULT_GOT, 0);
        $clip = fn () => UploadedFile::fake()->create('turn-0.webm', 40, 'audio/webm');

        $this->actingAs($holder);
        Livewire::test('games.play', ['pairGame' => $pairGame])->set('voiceClip', $clip())->call('saveVoiceClip', 'turn-0');
        $this->assertNull(VoiceFeedback::existingFor($holder, $pairGame));

        $this->actingAs($guesser);
        Livewire::test('games.play', ['pairGame' => $pairGame])->set('voiceClip', $clip())->call('saveVoiceClip', 'turn-0');
        $this->assertSame('Turn 1 · your questions (the secret: cat)', VoiceFeedback::existingFor($guesser, $pairGame)->clips[0]['label']);
    }

    /**
     * A joined Just a Minute game whose first talk is running: user A
     * talks about "Your favourite food", user B listens.
     */
    private function minuteGame(): PairGame
    {
        return $this->playingGame([
            'game' => PairGame::GAME_MINUTE,
            'rules' => ['turn_seconds' => 60, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 5],
            'deck' => [
                ['word' => 'Your favourite food', 'words' => ['delicious', 'spicy', 'recipe', 'meal'], 'source' => 'M04 · Food & Lifestyle', 'bonus' => [], 'key' => 'jam:food'],
                ['word' => 'A perfect weekend', 'words' => ['hang out', 'for fun', 'work out', 'into'], 'source' => 'M05 · Hobbies', 'bonus' => [], 'key' => 'jam:weekend'],
            ],
        ]);
    }

    public function test_just_a_minute_topics_come_with_four_words_of_their_mission(): void
    {
        $mission = $this->makeMission('M04', ['delicious', 'spicy', 'recipe', 'meal', 'snack']);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->finishDayOne($me, $mission);
        $this->finishDayOne($friend, $mission);
        $deck = app(JustAMinuteDeck::class);

        $rules = $deck->rulesFor($me, $friend, 5);
        $cards = collect($deck->cardsFor($me, $friend, $rules));

        $this->assertSame(JustAMinuteDeck::BEGINNER_TALK_SECONDS, $rules['turn_seconds']);
        $this->assertContains('Your favourite food', $cards->pluck('word'));
        $this->assertCount(4, $cards->first()['words']);
        $this->assertEmpty(array_diff($cards->first()['words'], ['delicious', 'spicy', 'recipe', 'meal', 'snack']));
    }

    public function test_the_listener_ticks_words_once_then_asks_one_question_and_the_two_swap(): void
    {
        $pairGame = $this->minuteGame();
        [$talker, $listener] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($talker);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('hear', 'spicy');
        $this->assertSame(0, $pairGame->refresh()->score, 'The talker cannot tick their own words.');

        $this->actingAs($listener);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('Your favourite food')
            ->call('hear', 'spicy')
            ->call('hear', 'spicy')
            ->call('hear', 'pizza')
            ->call('asked');
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->refresh()->score);
        $this->assertTrue($pairGame->isPlaying(), 'No question before the talk is over.');

        $this->actingAs($talker);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('doneTalking');
        $this->assertSame(PairGame::PHASE_ASK, $pairGame->refresh()->phase());

        $this->actingAs($listener);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('asked');

        $pairGame->refresh();
        $this->assertSame(2 * PairGame::POINTS_WORD, $pairGame->score);
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertTrue($pairGame->explainer()->is($listener));
        $this->assertSame(1, $pairGame->creditFor($talker));
        $this->assertEqualsCanonicalizing(['delicious', 'recipe', 'meal'], $pairGame->missedWords());
    }

    public function test_when_the_minute_is_up_the_question_comes_and_without_one_the_turn_still_ends(): void
    {
        $pairGame = $this->minuteGame();

        $this->travel(61)->seconds();
        $pairGame->timeUp(0);
        $this->assertSame(PairGame::PHASE_ASK, $pairGame->refresh()->phase());

        $this->travel(PairGame::ASK_SECONDS + 1)->seconds();
        $pairGame->timeUp(0);

        $this->assertSame(PairGame::STAGE_READY, $pairGame->refresh()->stage);
        $this->assertSame(0, $pairGame->score);
    }

    /**
     * A joined Story Chain game (one story of 4 turns) whose first turn is
     * running: user A tells first.
     */
    private function storyGame(): PairGame
    {
        $line = 'Every morning, Lily wakes up at six — but today there is no coffee.';

        return $this->playingGame([
            'game' => PairGame::GAME_STORY,
            'rules' => ['turn_seconds' => 30, 'turns' => 4, 'story_turns' => 4, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 5],
            'deck' => collect(['wake up', 'commute', 'usually', 'tired'])->map(fn (string $word, int $i) => [
                'word' => $word, 'story' => 0, 'line' => $line, 'image' => null, 'source' => 'M01 · My Daily Life', 'bonus' => [], 'key' => 'story:M01:'.$i,
            ])->all(),
        ]);
    }

    public function test_a_story_has_a_first_line_of_its_mission_and_one_mission_word_per_turn(): void
    {
        $mission = $this->makeMission('M01', ['wake up', 'commute', 'usually', 'tired', 'breakfast']);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $this->finishDayOne($me, $mission);
        $this->finishDayOne($friend, $mission);
        $deck = app(StoryChainDeck::class);

        $cards = collect($deck->cardsFor($me, $friend, $deck->rulesFor($me, $friend, 10)));

        $this->assertCount(2 * StoryChainDeck::STORY_TURNS, $cards);
        $this->assertSame([0, 1], $cards->pluck('story')->unique()->values()->all());
        $this->assertContains($cards->first()['line'], config('story_chain.M01'));
        $this->assertEmpty($cards->pluck('word')->diff(['wake up', 'commute', 'usually', 'tired', 'breakfast']));
    }

    public function test_the_story_passes_on_by_itself_and_the_listener_ticks_the_word_once(): void
    {
        $pairGame = $this->storyGame();
        [$first, $second] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($first);
        Livewire::test('games.play', ['pairGame' => $pairGame])->assertSee('wake up')->call('used');
        $this->assertSame(0, $pairGame->refresh()->score, 'The teller cannot tick their own word.');

        $this->actingAs($second);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('used')->call('used');
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->refresh()->score);

        $this->actingAs($first);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('doneTelling');

        $pairGame->refresh();
        $this->assertTrue($pairGame->isPlaying(), 'No Start between the turns of a story.');
        $this->assertTrue($pairGame->explainer()->is($second));
        $this->assertSame('commute', $pairGame->currentCard()['word']);

        // Time up passes it on too; after the last turn the game is over.
        $this->travel(31)->seconds();
        $pairGame->timeUp(1);
        $pairGame->refresh()->doneTelling($first);
        $pairGame->refresh()->doneTelling($second);

        $pairGame->refresh();
        $this->assertTrue($pairGame->isFinished());
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->score);
        $this->assertSame(1, $pairGame->creditFor($first));
        $this->assertEqualsCanonicalizing(['commute', 'usually', 'tired'], $pairGame->missedWords());
    }

    public function test_the_result_tells_the_whole_story_from_both_phones(): void
    {
        $pairGame = $this->storyGame();
        [$first, $second] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];
        foreach (range(0, 3) as $turn) {
            $pairGame->refresh()->doneTelling($pairGame->explainer());
        }
        VoiceFeedback::for($first, $pairGame)->update(['clips' => [['part' => 'turn-0', 'label' => 'x', 'path' => 'a.webm', 'transcript' => 'She woke up and went to the shop.']]]);
        VoiceFeedback::for($second, $pairGame)->update(['clips' => [['part' => 'turn-1', 'label' => 'x', 'path' => 'b.webm', 'transcript' => 'The shop was closed.']]]);

        $this->actingAs($first);
        Livewire::test('games.play', ['pairGame' => $pairGame->refresh()])
            ->assertSee('Your story')
            ->assertSee('Every morning, Lily wakes up at six')
            ->assertSee('She woke up and went to the shop.')
            ->assertSee('The shop was closed.');
    }

    public function test_every_playable_game_has_a_short_persian_guide_on_its_setup_and_game_screens(): void
    {
        foreach (PairGame::PLAYABLE as $game) {
            $this->assertCount(3, PairGame::guide($game), $game.' needs its three guide lines.');
        }

        $this->actingAs(User::factory()->create());
        $this->get(route('games.setup', PairGame::GAME_WHO))
            ->assertSee('چطور بازی کنیم؟')
            ->assertSee(PairGame::guide(PairGame::GAME_WHO)[0]);

        $pairGame = $this->playingGame();
        $this->actingAs($pairGame->startedBy);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSeeHtml('aria-label="How to play"')
            ->assertSee(PairGame::guide(PairGame::GAME_TABOO)[0]);
    }

    /**
     * A joined Role-play game (2 scenes) whose first scene is running:
     * user A plays 'a' (the customer), user B plays 'b' (the waiter).
     */
    private function roleGame(): PairGame
    {
        return $this->playingGame([
            'game' => PairGame::GAME_ROLE,
            'rules' => ['turn_seconds' => 120, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 4],
            'deck' => [[
                'word' => 'At a restaurant',
                'a' => ['role' => 'A vegetarian customer', 'goal' => 'Order a dish with no meat.', 'help' => ['Is there any meat in this?', 'Can I have…?', 'I’d like…']],
                'b' => ['role' => 'The waiter', 'goal' => 'Sell a dessert.', 'help' => ['Would you like…?', 'Today we have…', 'How about…?']],
                'options_a' => ['Book a table.', 'Order a dish with no meat.', 'Get a free dessert.'],
                'options_b' => ['Sell a dessert.', 'Close the kitchen early.', 'Find a food blogger.'],
                'source' => 'M04 · Role-play', 'bonus' => [], 'key' => 'role:M04:restaurant',
            ], [
                'word' => 'Dinner for guests',
                'a' => ['role' => 'A host', 'goal' => 'Get a menu.', 'help' => ['a', 'b', 'c']],
                'b' => ['role' => 'A cook', 'goal' => 'Share a recipe.', 'help' => ['a', 'b', 'c']],
                'options_a' => ['Get a menu.', 'x', 'y'], 'options_b' => ['Share a recipe.', 'x', 'y'],
                'source' => 'M04 · Role-play', 'bonus' => [], 'key' => 'role:M04:dinner',
            ]],
        ]);
    }

    public function test_every_mission_has_four_role_play_scenes_with_a_real_goal_among_the_options(): void
    {
        foreach (config('role_play') as $code => $scenes) {
            $this->assertCount(4, $scenes, $code);
        }

        $me = User::factory()->create();
        $friend = User::factory()->create();
        $card = app(RolePlayDeck::class)->cardsFor($me, $friend, ['turns' => 2, 'level' => 1])[0];

        $this->assertContains($card['a']['goal'], $card['options_a']);
        $this->assertContains($card['b']['goal'], $card['options_b']);
        $this->assertCount(3, $card['a']['help']);
    }

    public function test_each_player_sees_only_their_own_secret_goal(): void
    {
        $pairGame = $this->roleGame();
        [$customer, $waiter] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($customer);
        Livewire::test('games.play', ['pairGame' => $pairGame])->assertSee('Order a dish with no meat.')->assertDontSee('Sell a dessert.');

        $this->actingAs($waiter);
        Livewire::test('games.play', ['pairGame' => $pairGame])->assertSee('Sell a dessert.')->assertDontSee('Order a dish with no meat.');
    }

    public function test_after_the_talk_both_guess_and_points_come_for_right_guesses_and_reached_goals(): void
    {
        $pairGame = $this->roleGame();
        [$customer, $waiter] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $pairGame->submitGuess($customer, 0, true);
        $this->assertSame([], $pairGame->refresh()->currentTurn()['current']['guesses'] ?? [], 'No guessing while still talking.');

        $this->actingAs($waiter);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('endTalk');
        $this->assertSame(PairGame::PHASE_GUESS, $pairGame->refresh()->phase());

        // The customer guesses the waiter's goal right and reached theirs;
        // the waiter guesses wrong and didn't reach theirs.
        $this->actingAs($customer);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('guess', 0, true)->call('guess', 1, false);
        $this->actingAs($waiter);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('guess', 0, false);

        $pairGame->refresh();
        $this->assertSame(2 * PairGame::POINTS_WORD, $pairGame->score);
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(1, $pairGame->creditFor($customer));
        $this->assertSame(0, $pairGame->creditFor($waiter));

        $this->actingAs($customer);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('Sell a dessert.')
            ->assertSee('Reached ✓');
    }

    public function test_when_the_guess_time_runs_out_the_scene_ends_with_the_guesses_given(): void
    {
        $pairGame = $this->roleGame();
        $customer = $pairGame->startedBy;

        $this->travel(121)->seconds();
        $pairGame->timeUp(0);
        $pairGame->refresh()->submitGuess($customer, 0, true);
        $this->travel(PairGame::GUESS_SECONDS + 1)->seconds();
        $pairGame->refresh()->timeUp(0);

        $pairGame->refresh();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(2 * PairGame::POINTS_WORD, $pairGame->score);
    }

    /**
     * A joined Two Truths game (2 turns) whose first turn is running: user
     * A tells, user B finds the lie.
     */
    private function truthsGame(): PairGame
    {
        return $this->playingGame([
            'game' => PairGame::GAME_TRUTHS,
            'rules' => ['turn_seconds' => 60, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 6],
            'deck' => [
                ['word' => 'Your last weekend', 'hint' => 'Past simple: I went… I ate…', 'source' => 'Two Truths', 'bonus' => [], 'key' => 'truths:Your last weekend'],
                ['word' => 'Your family', 'hint' => 'My brother is…', 'source' => 'Two Truths', 'bonus' => [], 'key' => 'truths:Your family'],
            ],
        ]);
    }

    public function test_two_truths_topics_open_with_the_missions_that_teach_their_grammar(): void
    {
        config(['pair_games.truth_topics' => [['Your morning', 'I get up…', 1], ['Your last weekend', 'I went…', 6], ['Your plans', 'I am going to…', 11]]]);
        $me = User::factory()->create();
        $friend = User::factory()->create();
        $deck = app(TwoTruthsDeck::class);

        $rules = $deck->rulesFor($me, $friend, 5);
        $this->assertSame(TwoTruthsDeck::BEGINNER_TELL_SECONDS, $rules['turn_seconds']);
        $this->assertSame(['Your morning'], collect($deck->cardsFor($me, $friend, $rules))->pluck('word')->all());
        $this->assertEqualsCanonicalizing(['Your morning', 'Your last weekend'], collect($deck->cardsFor($me, $friend, [...$rules, 'level' => 6]))->pluck('word')->all());
    }

    public function test_only_the_teller_marks_the_lie_and_the_listener_finds_it_for_a_point(): void
    {
        $pairGame = $this->truthsGame();
        [$teller, $listener] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $this->actingAs($listener);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('Your last weekend')
            ->assertDontSee('WHICH ONE IS YOUR LIE?')
            ->call('markLie', 1)
            ->call('pickLie', 1);
        $this->assertArrayNotHasKey('lie', $pairGame->refresh()->currentTurn()['current']);

        $this->actingAs($teller);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('WHICH ONE IS YOUR LIE?')
            ->call('doneSaying');
        $this->assertSame(PairGame::PHASE_TALK, $pairGame->refresh()->phase(), 'Done needs a marked lie.');

        Livewire::test('games.play', ['pairGame' => $pairGame])->call('markLie', 2)->call('doneSaying');
        $this->assertSame(PairGame::PHASE_GUESS, $pairGame->refresh()->phase());

        $this->actingAs($listener);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('WHICH ONE IS THE LIE?')
            ->call('pickLie', 2)
            ->assertSee('Lie found! It was #2');

        $pairGame->refresh();
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->score);
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(1, $pairGame->creditFor($listener));
        $this->assertTrue($pairGame->explainer()->is($listener), 'The two swap.');
    }

    public function test_a_wrong_pick_scores_nothing_and_the_result_lists_each_topic(): void
    {
        $pairGame = $this->truthsGame();
        [$teller, $listener] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $pairGame->markLie($teller, 3);
        $pairGame->refresh()->doneSaying($teller);
        $pairGame->refresh()->pickLie($listener, 1);

        $pairGame->refresh()->startTurn($listener);
        $pairGame->refresh()->markLie($listener, 1);
        $pairGame->refresh()->doneSaying($listener);
        $pairGame->refresh()->pickLie($teller, 1);

        $pairGame->refresh();
        $this->assertTrue($pairGame->isFinished());
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->score);

        $this->actingAs($teller);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('found (lies)')
            ->assertSee('Your family')
            ->assertSee('the lie was #3')
            ->assertSee('Fooled');
    }

    public function test_when_time_runs_out_a_marked_lie_goes_to_the_guess_and_no_lie_ends_the_turn(): void
    {
        $pairGame = $this->truthsGame();
        $teller = $pairGame->startedBy;

        $pairGame->markLie($teller, 2);
        $this->travel(61)->seconds();
        $pairGame->refresh()->timeUp(0);
        $this->assertSame(PairGame::PHASE_GUESS, $pairGame->refresh()->phase());

        $this->travel(PairGame::GUESS_SECONDS + 1)->seconds();
        $pairGame->refresh()->timeUp(0);
        $pairGame->refresh();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(0, $pairGame->score);

        $listener = $pairGame->partnerFor($teller);
        $pairGame->startTurn($listener);
        $this->travel(61)->seconds();
        $pairGame->refresh()->timeUp(1);
        $this->assertTrue($pairGame->refresh()->isFinished(), 'Without a marked lie the turn just ends.');
    }

    /**
     * A joined Would You Rather game (2 questions) whose first question is
     * open: user A opened it.
     */
    private function ratherGame(): PairGame
    {
        return $this->playingGame([
            'game' => PairGame::GAME_RATHER,
            'rules' => ['turn_seconds' => 60, 'turns' => 2, 'skips' => null, 'bonus' => false, 'starters' => 0, 'level' => 4],
            'deck' => [
                ['word' => 'Would you rather cook dinner every night or wash the dishes every night?', 'a' => 'cook dinner every night', 'b' => 'wash the dishes every night', 'source' => 'M04 · Would you rather', 'bonus' => [], 'key' => 'rather:M04:cook'],
                ['word' => 'Would you rather eat only pizza or eat only rice?', 'a' => 'eat only pizza', 'b' => 'eat only rice', 'source' => 'M04 · Would you rather', 'bonus' => [], 'key' => 'rather:M04:pizza'],
            ],
        ]);
    }

    public function test_every_mission_has_four_would_you_rather_questions_from_the_shared_missions(): void
    {
        foreach (config('would_you_rather') as $code => $questions) {
            $this->assertCount(4, $questions, $code);
        }

        $me = User::factory()->create();
        $friend = User::factory()->create();
        $cards = app(WouldYouRatherDeck::class)->cardsFor($me, $friend, ['turns' => 4, 'level' => 1]);

        $this->assertCount(4, $cards);
        $this->assertSame(['M01 · Would you rather'], collect($cards)->pluck('source')->unique()->values()->all());
        $this->assertStringStartsWith('Would you rather '.$cards[0]['a'].' or '.$cards[0]['b'], $cards[0]['word']);
    }

    public function test_picks_stay_secret_until_both_picked_then_right_guesses_score(): void
    {
        $pairGame = $this->ratherGame();
        [$opener, $friend] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        // The opener would rather cook and guesses the friend washes; the
        // friend would rather wash and guesses the opener washes too.
        $this->actingAs($opener);
        Livewire::test('games.play', ['pairGame' => $pairGame])->call('pickSide', 'a', 'b')->call('pickSide', 'b', 'b')->assertSee('Waiting for');
        $this->assertSame(['mine' => 'a', 'guess' => 'b'], $pairGame->refresh()->currentTurn()['current']['picks'][$opener->id]);

        $this->actingAs($friend);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('PICK A SIDE')
            ->assertDontSee('didn’t guess it')
            ->call('pickSide', 'b', 'b')
            ->assertSee('SAY WHY')
            ->assertSee('Wash the dishes every night')
            ->assertSee('guessed it ✓');

        $pairGame->refresh();
        $this->assertSame(PairGame::PHASE_TALK, $pairGame->phase());
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->score);

        Livewire::test('games.play', ['pairGame' => $pairGame])->call('doneWhy');
        $pairGame->refresh();
        $this->assertSame(PairGame::STAGE_READY, $pairGame->stage);
        $this->assertSame(PairGame::POINTS_WORD, $pairGame->score, 'Points are counted once.');
        $this->assertSame(1, $pairGame->creditFor($opener));
        $this->assertSame(0, $pairGame->creditFor($friend));
        $this->assertTrue($pairGame->explainer()->is($friend), 'The friend opens the next question.');
    }

    public function test_when_pick_time_runs_out_the_picks_so_far_are_shown_and_no_pick_ends_the_question(): void
    {
        $pairGame = $this->ratherGame();
        [$opener, $friend] = [$pairGame->startedBy, $pairGame->partnerFor($pairGame->startedBy)];

        $pairGame->pickSide($opener, 'a', 'a');
        $this->travel(PairGame::PICK_SECONDS + 1)->seconds();
        $pairGame->refresh()->timeUp(0);
        $this->assertSame(PairGame::PHASE_TALK, $pairGame->refresh()->phase());
        $this->assertSame(0, $pairGame->score);

        $this->travel(61)->seconds();
        $pairGame->refresh()->timeUp(0);
        $pairGame->refresh()->startTurn($friend);
        $this->travel(PairGame::PICK_SECONDS + 1)->seconds();
        $pairGame->refresh()->timeUp(1);

        $pairGame->refresh();
        $this->assertTrue($pairGame->isFinished());

        $this->actingAs($opener);
        Livewire::test('games.play', ['pairGame' => $pairGame])
            ->assertSee('guessed right')
            ->assertSee('Cook dinner every night')
            ->assertSee('No pick');
    }
}
