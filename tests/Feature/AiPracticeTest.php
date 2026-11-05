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
        Livewire::test('pair-practice.index')->assertSee('Practice with AI')->assertSee('Your voice is recorded')->assertSee('Finish a day first');
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
            ->assertSee('AI’S TURN')
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
            ->assertSee('The AI got it!');

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

    public function test_someone_elses_practice_cannot_be_opened(): void
    {
        $practice = AiPractice::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('ai-practice.show', $practice))->assertForbidden();
        $this->actingAs($practice->learner)->get(route('ai-practice.show', $practice))->assertOk();
    }

    public function test_only_the_two_games_the_ai_can_play_offer_it(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->actingAs($learner);

        Livewire::test('games.setup', ['game' => PairGame::GAME_WHO])->assertSee('Play with AI')->call('playWithAi');
        $this->assertSame(PairGame::GAME_WHO, AiPractice::latest('id')->first()->kind);

        Livewire::test('games.setup', ['game' => PairGame::GAME_RATHER])->assertSee('Play with AI');
        Livewire::test('games.setup', ['game' => PairGame::GAME_TABOO])->assertDontSee('Play with AI');
    }
}
