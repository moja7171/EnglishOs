<?php

namespace Tests\Feature;

use App\Models\AiPractice;
use App\Models\DayActivity;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use App\Services\AiPractices;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Practice with the AI as a classmate — see App\Models\AiPractice.
 */
class AiPracticeTest extends TestCase
{
    use RefreshDatabase;

    private function learnerWithAFinishedDay(array $words = ['breakfast', 'lunch', 'dinner', 'kitchen']): User
    {
        $mission = Mission::create([
            'code' => 'M01',
            'title' => 'My Daily Life',
            'module' => 'Me',
            'outcome' => 'I can talk.',
            'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [
                ['key' => 'vocabulary_builder_1', 'words' => collect($words)->map(fn (string $word) => ['phrase' => $word, 'meaning_fa' => 'معنی '.$word])->all()],
            ]]],
        ]);
        $learner = User::factory()->create();
        $run = MissionRun::findOrStart($learner, $mission);
        Evidence::create(['mission_run_id' => $run->id, 'phase' => 'vocabulary_builder_1', 'type' => Evidence::TYPE_TEXT, 'content_ref' => '[]']);

        return $learner;
    }

    private function heard(string $text): void
    {
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn($text));
    }

    private function say(AiPractice $practice): Testable
    {
        return Livewire::test('ai-practice.session', ['practice' => $practice])
            ->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))
            ->call('submitAnswer');
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function gamePractice(string $game, array $cards, int $position = 0): AiPractice
    {
        $practice = AiPractice::factory()->create([
            'kind' => $game,
            'deck' => ['game' => ['game' => $game, 'cards' => $cards]],
            'stage' => AiPractice::STAGE_GAME,
            'position' => $position,
        ]);
        $this->actingAs($practice->learner);

        return $practice;
    }

    public function test_a_round_is_drawn_from_the_days_the_learner_finished_and_ends_with_a_game(): void
    {
        $learner = $this->learnerWithAFinishedDay();

        $practice = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

        $this->assertEqualsCanonicalizing(['breakfast', 'lunch', 'dinner', 'kitchen'], collect($practice->cards(AiPractice::STAGE_VOCABULARY))->pluck('answer')->all());
        $this->assertContains($practice->game(), AiPractice::GAMES);
        $this->assertSame(AiPractice::STAGE_VOCABULARY, $practice->stage);
    }

    public function test_no_finished_day_means_no_round_and_the_page_says_so(): void
    {
        $learner = User::factory()->create();

        $this->assertNull(app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE));

        $this->actingAs($learner);
        Livewire::test('pair-practice.index')->assertSee('Teddy')->assertSee('Your voice is recorded')->assertSee('Finish a day first');
    }

    public function test_the_open_practice_is_picked_up_and_a_new_round_ends_with_the_other_game(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $first = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

        $this->assertTrue($first->is(app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE)));

        $first->finish();
        $second = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

        $this->assertFalse($first->is($second));
        $this->assertNotSame($first->game(), $second->game());
    }

    public function test_the_ai_answers_every_other_card_with_no_ai_call_and_the_learner_only_listens(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldNotReceive('transcribe'));

        $this->assertTrue($practice->learnerAnswers());

        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->assertSee('YOUR TURN')
            ->call('next')
            ->assertSee('TEDDY’S TURN')
            ->assertSee('lunch');

        $this->assertFalse($practice->refresh()->learnerAnswers());
    }

    public function test_a_card_of_my_own_error_log_and_a_talk_card_are_always_mine(): void
    {
        $practice = AiPractice::factory()->make(['stage' => AiPractice::STAGE_GRAMMAR, 'position' => 1, 'deck' => [
            AiPractice::STAGE_GRAMMAR => [['prompt' => 'a', 'answer' => 'b'], ['prompt' => 'I goes.', 'answer' => 'I go.', 'owner_id' => 1], ['prompt' => 'c', 'answer' => 'd'], ['type' => 'talk', 'question' => 'Talk', 'answer' => '', 'words' => ['x']]],
        ]]);

        $this->assertTrue($practice->learnerAnswers());

        $practice->position = 3;
        $this->assertTrue($practice->learnerAnswers());
    }

    public function test_the_ai_checks_a_said_word_and_a_missed_one_waits_in_my_words(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);
        $this->heard('I think it is dinner.');

        $this->say($practice)->assertSee('Not yet')->assertSee('breakfast');

        $practice->refresh();
        $this->assertSame(0, $practice->score);
        $this->assertSame(1, $practice->answered);

        $practice->finish();
        $this->assertDatabaseHas('vocabulary_words', ['learner_id' => $practice->learner_id, 'word' => 'breakfast']);
    }

    public function test_a_right_word_and_a_sentence_with_a_small_mishearing_both_count(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);
        $this->heard('Breakfast.');

        $this->say($practice)->assertSee('Right');
        $this->assertSame(1, $practice->refresh()->score);

        $this->assertTrue(AiPractice::saidCorrectly('She goes to work', 'She goes to work.'));
        $this->assertTrue(AiPractice::saidCorrectly('she goes to the work', 'She goes to work.'));
        $this->assertFalse(AiPractice::saidCorrectly('She go to work tomorrow morning', 'I had lunch.'));
    }

    public function test_nothing_heard_keeps_the_card_for_another_try(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);
        $this->heard('   ');

        $this->say($practice)->assertSee('I couldn’t hear you')->assertSee('Skip');

        $this->assertNull($practice->refresh()->current);
        $this->assertSame(0, $practice->answered);
    }

    public function test_a_transcription_failure_keeps_the_card_too(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andThrow(new RuntimeException('down')));

        $this->say($practice)->assertSee('Skip');

        $this->assertNull($practice->refresh()->current);
    }

    public function test_a_spoken_answer_gets_one_line_and_goes_on_without_it_when_the_ai_is_down(): void
    {
        $practice = AiPractice::factory()->create(['stage' => AiPractice::STAGE_SPEAKING]);
        $this->actingAs($practice->learner);
        $this->heard('I wake up at seven.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        $this->say($practice)->assertSee('I wake up at seven.')->assertSee('Next');

        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_who_am_i_the_ai_answers_my_question_and_naming_the_secret_wins(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_WHO, [['word' => 'teacher', 'category' => 'a job'], ['word' => 'cat', 'category' => 'an animal']]);
        $this->heard('Are you a teacher?');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"answer":"yes","guessed":true}'));

        $this->say($practice)->assertSee('You got it!')->assertSee('The secret: teacher');

        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_who_am_i_ends_after_the_last_question(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_WHO, [['word' => 'teacher', 'category' => 'a job']]);
        $practice->update(['current' => ['qa' => array_fill(0, AiPractice::MAX_QUESTIONS - 1, ['q' => 'Do you?', 'a' => 'no'])]]);
        $this->heard('Do you work?');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"answer":"no","guessed":false}'));

        $this->say($practice)->assertSee('Not this time');

        $this->assertFalse($practice->refresh()->current['won']);
    }

    public function test_who_am_i_a_failed_ai_answer_keeps_my_question_count(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_WHO, [['word' => 'teacher', 'category' => 'a job']]);
        $this->heard('Do you work?');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        $this->say($practice);

        $this->assertNull($practice->refresh()->current);
    }

    public function test_who_am_i_the_ai_asks_about_my_secret_and_i_tap_the_answers(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_WHO, [['word' => 'teacher', 'category' => 'a job'], ['word' => 'cat', 'category' => 'an animal']]);
        $practice->update(['current' => ['qa' => [], 'won' => true]]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"question":"Do you have four legs?"}', '{"question":"Are you a cat?"}'));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])
            ->call('next')
            ->assertSee('Your secret')
            ->assertSee('Do you have four legs?')
            ->call('answerAi', 'yes')
            ->assertSee('Are you a cat?')
            ->call('aiGotIt')
            ->assertSee('Teddy got it!');

        $this->assertSame(1, $practice->refresh()->score);
        $component->call('next')->assertSee('Well done!');
    }

    public function test_would_you_rather_the_ai_picks_says_why_and_i_say_why(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_RATHER, [['word' => 'Tea or coffee?', 'a' => 'Tea', 'b' => 'Coffee']]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('I’d rather have this one.', 'Nice reason.'));
        $this->heard('I would rather have tea because it is hot.');

        $component = Livewire::test('ai-practice.session', ['practice' => $practice])
            ->call('pickSide', 'a', 'b')
            ->assertSee('I’d rather have this one.');

        $component->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSee('I would rather have tea because it is hot.')
            ->call('next')
            ->assertSee('Well done!');

        $current = $practice->refresh();
        $this->assertSame(1, $current->answered);
    }

    public function test_would_you_rather_goes_on_when_the_ai_has_no_reason(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_RATHER, [['word' => 'Tea or coffee?', 'a' => 'Tea', 'b' => 'Coffee']]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->call('pickSide', 'a', 'a')
            ->assertSee('It sounds better to me.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minuteCards(): array
    {
        return [
            ['word' => 'My morning', 'words' => ['coffee', 'early', 'bus', 'cook'], 'source' => 'M01 · Daily life'],
            ['word' => 'My weekend', 'words' => ['park', 'friends', 'movie', 'sleep'], 'source' => 'M01 · Daily life'],
        ];
    }

    public function test_just_a_minute_alone_draws_a_topic_and_its_words_from_the_finished_days(): void
    {
        $learner = $this->learnerWithAFinishedDay();

        $practice = app(AiPractices::class)->start($learner, PairGame::GAME_MINUTE);

        $this->assertSame(PairGame::GAME_MINUTE, $practice->game());
        $this->assertNotEmpty($practice->currentCard()['words']);
        $this->assertContains($practice->currentCard()['word'], config('just_a_minute.M01'));
    }

    public function test_just_a_minute_my_talk_scores_the_words_i_used_and_teddy_asks_about_it(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards());
        $this->heard('I get up early, and I am cooking eggs with COFFEE.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('What do you cook?'));

        $this->say($practice)->assertSee('What do you cook?')->assertDontSee('Next');

        $practice->refresh();
        $this->assertEqualsCanonicalizing(['coffee', 'early', 'cook'], $practice->current['used']);
        $this->assertSame(3, $practice->score);
    }

    public function test_just_a_minute_my_answer_to_teddy_ends_my_turn_and_she_talks_next(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards());
        $practice->update(['current' => ['heard' => 'My talk.', 'used' => [], 'question' => 'Why?']]);
        $this->heard('Because I like it.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I went to the park with friends.'));

        $this->say($practice)->assertSee('Because I like it.')->assertSee('Next')->call('next')->assertSee('TEDDY’S TURN');

        $practice->refresh();
        $this->assertSame(1, $practice->answered);
        $this->assertSame(['park', 'friends'], $practice->current['used']);
    }

    public function test_just_a_minute_a_failed_question_never_stops_my_turn(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards());
        $this->heard('I drink coffee.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        $this->say($practice)->assertSee('Can you tell me more?');

        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_just_a_minute_nothing_heard_keeps_my_turn(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards());
        $this->heard('');

        $this->say($practice)->assertSee('I couldn’t hear you');

        $this->assertNull($practice->refresh()->current);
    }

    public function test_just_a_minute_a_tapped_word_scores_only_if_teddy_said_it_and_a_second_tap_takes_it_back(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards(), position: 1);
        $practice->update(['current' => ['talk' => 'I went to the park.', 'used' => ['park'], 'ticked' => []]]);

        $component = Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])->call('tickWord', 'park');
        $this->assertSame(1, $practice->refresh()->score);

        $component->call('tickWord', 'movie');
        $this->assertSame(1, $practice->refresh()->score);

        $component->call('tickWord', 'park')->call('tickWord', 'not on the card');
        $practice->refresh();
        $this->assertSame(0, $practice->score);
        $this->assertSame(['movie'], $practice->current['ticked']);
    }

    public function test_just_a_minute_teddy_answers_my_question_about_her_talk(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards(), position: 1);
        $practice->update(['current' => ['talk' => 'I went to the park.', 'used' => ['park'], 'ticked' => []]]);
        $this->heard('Who went with you?');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('My little brother!'));

        $this->say($practice->refresh())->assertSee('Who went with you?')->assertSee('My little brother!')->call('next')->assertSee('Well done!');

        $practice->refresh();
        $this->assertSame(1, $practice->score);
        $this->assertSame(1, $practice->answered);
    }

    public function test_just_a_minute_teddy_s_failed_talk_can_be_tried_again(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_MINUTE, $this->minuteCards(), position: 1);
        $failures = 1;
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturnUsing(function () use (&$failures) {
            return $failures-- > 0 ? throw new RuntimeException('down') : 'I love the park.';
        }));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiTalks')->assertSee('Try again');
        $this->assertNull($practice->refresh()->current);

        $component->call('aiTalks')->assertDontSee('Try again');
        $this->assertSame('I love the park.', $practice->refresh()->current['talk']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function storyCards(): array
    {
        return collect(['river', 'boat', 'rain', 'home'])
            ->map(fn (string $word) => ['word' => $word, 'story' => 0, 'line' => 'Sara found an old map.', 'image' => null, 'source' => 'M01 · Daily life'])
            ->all();
    }

    public function test_story_chain_is_one_long_story_alone_and_a_short_one_at_the_end_of_a_round(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->assertCount(8, app(AiPractices::class)->start($learner, PairGame::GAME_STORY)->cards(AiPractice::STAGE_GAME));

        $lastRound = AiPractice::factory()->create(['learner_id' => $learner->id, 'deck' => ['game' => ['game' => PairGame::GAME_MINUTE, 'cards' => $this->minuteCards()]]]);
        $lastRound->finish();

        $round = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

        $this->assertSame(PairGame::GAME_STORY, $round->game());
        $this->assertCount(4, $round->cards(AiPractice::STAGE_GAME));
    }

    public function test_story_chain_my_line_scores_my_word_and_teddy_goes_on_with_hers(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_STORY, $this->storyCards());
        $this->heard('She walked to the river.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages) => str_contains($messages[0]['text'], 'She walked to the river.') && str_contains($messages[0]['text'], 'Your word: boat'))
            ->andReturn('She saw a small boat.'));

        $this->say($practice)->assertSee('She walked to the river.')->assertSee('She saw a small boat.')->assertSee('rain');

        $practice->refresh();
        $this->assertSame(1, $practice->score);
        $this->assertSame(1, $practice->answered);
        $this->assertSame(2, $practice->position);
    }

    public function test_story_chain_a_line_without_my_word_still_goes_on_with_no_point(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_STORY, $this->storyCards());
        $this->heard('She went out.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('A boat came.'));

        $this->say($practice);

        $practice->refresh();
        $this->assertSame(0, $practice->score);
        $this->assertSame(1, $practice->answered);
    }

    public function test_story_chain_teddy_ends_the_story_on_the_last_turn_and_next_finishes(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_STORY, $this->storyCards(), position: 2);
        $practice->update(['current' => ['lines' => [['who' => 'you', 'text' => 'A.'], ['who' => 'teddy', 'text' => 'B.']]]]);
        $this->heard('It began to rain.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages, ?string $systemPrompt) => str_contains((string) $systemPrompt, 'ending'))
            ->andReturn('They went home happy.'));

        $component = $this->say($practice->refresh())->assertSee('YOUR STORY')->assertSee('They went home happy.')->assertDontSee('Use this word');

        $this->assertCount(4, $practice->refresh()->current['lines']);

        $component->call('next')->assertSee('Well done!');
    }

    public function test_story_chain_skip_lets_teddy_go_on_alone(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_STORY, $this->storyCards());
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('She took a boat.'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('next')->assertSee('She took a boat.');

        $practice->refresh();
        $this->assertSame(0, $practice->answered);
        $this->assertSame(2, $practice->position);
    }

    public function test_story_chain_goes_on_with_a_plain_line_when_the_ai_is_down_and_nothing_heard_keeps_my_turn(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_STORY, $this->storyCards());
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        $this->heard('');
        $this->say($practice)->assertSee('I couldn’t hear you');
        $this->assertSame(0, $practice->refresh()->position);

        $this->heard('She saw the river.');
        $this->say($practice->refresh())->assertSee('Then something surprising happened.');
        $this->assertSame(2, $practice->refresh()->position);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function truthsCards(): array
    {
        return [
            ['word' => 'Your family', 'hint' => 'My brother is…', 'source' => 'Two Truths'],
            ['word' => 'Your hobbies', 'hint' => 'I like…', 'source' => 'Two Truths'],
        ];
    }

    public function test_two_truths_alone_has_topics_even_before_a_finished_day(): void
    {
        $practice = app(AiPractices::class)->start(User::factory()->create(), PairGame::GAME_TRUTHS);

        $this->assertCount(4, $practice->cards(AiPractice::STAGE_GAME));
    }

    public function test_two_truths_i_mark_my_lie_first_and_only_then_can_speak(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards());
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldNotReceive('transcribe'));

        $this->say($practice)->assertDontSee('I heard');
        $this->assertNull($practice->refresh()->current);

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('markLie', 2)->assertSee('Say them')->call('markLie', 9);
        $this->assertSame(2, $practice->refresh()->current['lie']);
    }

    public function test_two_truths_teddy_finds_my_lie_for_a_point_and_missing_it_scores_nothing(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards());
        $practice->update(['current' => ['lie' => 2]]);
        $this->heard('One, I have a brother. Two, I have a horse. Three, I live in Tehran.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"pick":2,"line":"Number two, a horse in the city?"}', '{"pick":1,"line":"Number one!"}'));

        $this->say($practice->refresh())->assertSee('Teddy found it!')->assertSee('a horse in the city?')->assertSee('Next');
        $this->assertSame(1, $practice->refresh()->score);

        $practice->update(['current' => ['lie' => 2]]);
        $this->say($practice->refresh())->assertSee('You tricked Teddy!');
        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_two_truths_teddy_still_guesses_when_the_ai_is_down(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards());
        $practice->update(['current' => ['lie' => 1]]);
        $this->heard('One. Two. Three.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('not json'));

        $this->say($practice->refresh())->assertSee('I think number')->assertSee('Next');

        $this->assertSame(1, $practice->refresh()->answered);
    }

    public function test_two_truths_teddy_says_three_things_i_ask_and_pick_her_lie(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards());
        $practice->update(['current' => ['heard' => 'x', 'lie' => 1, 'pick' => 1, 'line' => 'y']]);
        $this->heard('Do you have a dog?');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"statements":["I have a brother.","I have a dog.","I live in Shiraz."],"lie":2}', 'Yes, he is very small.'));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])
            ->call('next')
            ->assertSee('TEDDY’S TURN')
            ->assertSee('I have a dog.')
            ->assertDontSee('The lie')
            ->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSee('Yes, he is very small.');

        $current = $practice->refresh()->current;
        $this->assertSame('I have a dog.', $current['statements'][$current['lie'] - 1]);

        $component->call('pickLie', $current['lie'])->assertSee('You found it!')->assertSee('The lie');

        $practice->refresh();
        $this->assertSame(1, $practice->score);
        $this->assertSame(2, $practice->answered);

        $component->call('pickLie', $current['lie'] % 3 + 1);
        $this->assertSame($current['lie'], $practice->refresh()->current['picked']);
    }

    public function test_two_truths_a_wrong_pick_scores_nothing_and_questions_stop_at_two(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards(), position: 1);
        $practice->update(['current' => ['statements' => ['a', 'b', 'c'], 'lie' => 2, 'asked' => [['q' => '1', 'a' => '1'], ['q' => '2', 'a' => '2']]]]);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldNotReceive('transcribe'));

        $this->say($practice->refresh())->call('pickLie', 3)->assertSee('Teddy tricked you!');

        $practice->refresh();
        $this->assertSame(0, $practice->score);
        $this->assertCount(2, $practice->current['asked']);
    }

    public function test_two_truths_a_bad_ai_reply_offers_try_again_and_a_good_one_shows_her_three(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TRUTHS, $this->truthsCards(), position: 1);
        $replies = ['{"statements":["only one"],"lie":1}', '{"statements":["a","b","c"],"lie":3}'];
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturnUsing(function () use (&$replies) {
            return array_shift($replies);
        }));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiTellsThree')->assertSee('Try again');
        $this->assertNull($practice->refresh()->current);

        $component->call('aiTellsThree');
        $current = $practice->refresh()->current;
        $this->assertSame('c', $current['statements'][$current['lie'] - 1]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tabooCards(): array
    {
        return [
            ['word' => 'breakfast', 'source' => 'M01 · Daily life', 'bonus' => [], 'key' => 'w:breakfast'],
            ['word' => 'kitchen', 'source' => 'M01 · Daily life', 'bonus' => [], 'key' => 'w:kitchen'],
        ];
    }

    public function test_taboo_alone_draws_four_words_and_asks_no_ai_for_bonus_words(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $learner->vocabularyWords()->create(['word' => 'umbrella', 'meaning_fa' => 'چتر', 'next_review_at' => now()->addDay()]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $practice = app(AiPractices::class)->start($learner, PairGame::GAME_TABOO);

        $this->assertCount(4, $practice->cards(AiPractice::STAGE_GAME));
    }

    public function test_taboo_teddy_guesses_my_word_with_no_clock_and_a_right_guess_scores(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards());
        $this->heard('You eat it in the morning.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('Breakfast.'));

        $this->say($practice)->assertSee('Teddy got it!')->assertSee('Next');

        $practice->refresh();
        $this->assertSame(1, $practice->score);
        $this->assertSame(1, $practice->answered);
    }

    public function test_taboo_saying_the_word_asks_for_another_way_with_no_ai_call(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards());
        $this->heard('It is my breakfast.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        $this->say($practice)->assertSee('You said the word!')->assertDontSee('Teddy got it!');

        $this->assertNull($practice->refresh()->current);
    }

    public function test_taboo_after_three_wrong_guesses_my_turn_ends_with_no_point(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards());
        $practice->update(['current' => ['tries' => [['said' => 'a', 'guess' => 'lunch'], ['said' => 'b', 'guess' => 'dinner']]]]);
        $this->heard('The first food of the day.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()
            ->withArgs(fn (array $messages) => str_contains($messages[0]['text'], 'lunch'))
            ->andReturn('snack'));

        $this->say($practice->refresh())->assertSee('Teddy couldn’t guess it');

        $practice->refresh();
        $this->assertSame(0, $practice->score);
        $this->assertFalse($practice->current['won']);
    }

    public function test_taboo_a_failed_guess_keeps_my_tries(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards());
        $this->heard('You eat it in the morning.');
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        $this->say($practice)->assertSee('Skip');

        $this->assertNull($practice->refresh()->current);
    }

    public function test_taboo_clues_come_in_one_call_and_a_wrong_guess_shows_the_next_with_no_ai(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards(), position: 1);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"clues":["It is a room.","Your kitchen has a fridge.","You cook there."]}'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiGivesClues')->assertSee('It is a room.')->assertDontSee('fridge')->assertDontSee('You cook there.');

        $this->assertSame(['It is a room.', 'You cook there.'], $practice->refresh()->current['clues']);

        $this->heard('Is it a bedroom?');
        $this->say($practice->refresh())->assertSee('You cook there.');
        $this->assertArrayNotHasKey('won', $practice->refresh()->current);

        $this->heard('The kitchens!');
        $this->say($practice->refresh())->assertSee('You got it!')->assertSee('kitchen');

        $practice->refresh();
        $this->assertSame(1, $practice->score);
        $this->assertSame(2, $practice->answered);
    }

    public function test_taboo_her_word_stays_hidden_and_shows_when_my_tries_run_out(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards(), position: 1);
        $practice->update(['current' => ['clues' => ['A room.'], 'guesses' => []]]);
        $this->heard('A bedroom?');

        Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])->assertDontSee('kitchen');

        $this->say($practice->refresh())->assertSee('Not this time')->assertSee('kitchen');

        $this->assertSame(0, $practice->refresh()->score);
    }

    public function test_taboo_clues_with_only_the_word_in_them_offer_try_again(): void
    {
        $practice = $this->gamePractice(PairGame::GAME_TABOO, $this->tabooCards(), position: 1);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"clues":["It is a kitchen."]}'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiGivesClues')->assertSee('Try again');

        $this->assertNull($practice->refresh()->current);
    }

    public function test_a_finished_practice_counts_for_my_day_and_never_as_a_game_with_a_friend(): void
    {
        $practice = AiPractice::factory()->create(['answered' => AiPractice::MIN_COUNTED_ANSWERS]);

        $practice->finish();

        $this->assertDatabaseHas('day_activities', ['learner_id' => $practice->learner_id, 'activity' => 'ai-practice']);
        $this->assertSame(0, DayActivity::whereIn('activity', ['pair', 'game'])->count());
        $this->assertSame(0, PairGame::count());
    }

    public function test_a_practice_ended_too_early_does_not_count(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('end')->assertSee('Well done!');

        $this->assertSame(0, DayActivity::count());
    }

    public function test_teddy_is_at_the_top_of_friends_even_with_no_friends_and_is_not_a_friend(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->actingAs($learner);

        Livewire::test('friends.index')->assertSee('Teddy')->assertSeeHtml(route('friends.teddy'));

        $this->assertSame(0, $learner->following()->count());
    }

    public function test_teddy_s_page_starts_a_round_and_a_game_and_says_so_when_nothing_is_finished(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->actingAs($learner);
        $this->get(route('friends.teddy'))->assertOk()->assertSee('Your AI classmate')->assertSee('Who am I?');

        Livewire::test('friends.teddy')->call('start', PairGame::GAME_WHO)->assertRedirect();
        $this->assertSame(PairGame::GAME_WHO, AiPractice::latest('id')->first()->kind);

        Livewire::test('friends.teddy')->call('start', AiPractice::KIND_PRACTICE)->assertRedirect();
        $this->assertSame(AiPractice::KIND_PRACTICE, AiPractice::latest('id')->first()->kind);

        $this->actingAs(User::factory()->create());
        Livewire::test('friends.teddy')->call('start', AiPractice::KIND_PRACTICE)->assertSee('Finish a day first.');
    }

    public function test_teddy_s_page_refuses_a_game_she_cannot_play(): void
    {
        $this->actingAs($this->learnerWithAFinishedDay());

        Livewire::test('friends.teddy')->call('start', PairGame::GAME_ROLE)->assertNotFound();

        $this->assertSame(0, AiPractice::count());
    }

    public function test_rounds_take_the_games_in_turn_and_the_list_wraps_around(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $played = [];

        // Ask & Answer has no situations written for this learner's mission, so a round skips it.
        $games = array_values(array_diff(AiPractice::GAMES, [PairGame::GAME_ASK]));

        foreach (range(0, count($games)) as $round) {
            $practice = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);
            $played[] = $practice->game();
            $practice->finish();
        }

        $this->assertSame($games[0], $played[0]);
        $this->assertSame($games[1], $played[1]);
        $this->assertSame($played[0], $played[count($games)]);
    }

    public function test_someone_elses_practice_cannot_be_opened(): void
    {
        $practice = AiPractice::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('ai-practice.show', $practice))->assertForbidden();
        $this->actingAs($practice->learner)->get(route('ai-practice.show', $practice))->assertOk();
    }

    public function test_teddy_is_offered_only_on_the_games_she_can_play(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->actingAs($learner);

        Livewire::test('games.setup', ['game' => PairGame::GAME_WHO])->assertSee('Teddy')->call('playWithAi');
        $this->assertSame(PairGame::GAME_WHO, AiPractice::latest('id')->first()->kind);

        foreach (AiPractice::GAMES as $game) {
            Livewire::test('games.setup', ['game' => $game])->assertSee('With Teddy your voice is recorded');
        }

        Livewire::test('games.setup', ['game' => PairGame::GAME_ROLE])->assertDontSee('With Teddy');
    }
}
