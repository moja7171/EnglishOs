<?php

namespace Tests\Feature;

use App\Models\AiPractice;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use App\Models\VocabularyWord;
use App\Services\AiPractices;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\PairPractice;
use App\Services\TeddyLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Teddy slips now and then and the learner catches it, his own words come
 * first for the learner, the guessing game never repeats a question and he
 * plays at the level the learner's last games call for.
 */
class TeddyPlaysFairTest extends TestCase
{
    use RefreshDatabase;

    private function learnerWithAFinishedDay(array $words = ['breakfast', 'lunch', 'dinner', 'kitchen', 'bread', 'butter', 'jam', 'honey']): User
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

    /**
     * @param  list<int>  $slips  vocabulary positions Teddy slips on
     */
    private function roundWithSlips(array $slips): AiPractice
    {
        $practice = AiPractice::factory()->create();
        $practice->update(['deck' => [...$practice->deck, 'slips' => [AiPractice::STAGE_VOCABULARY => $slips]], 'position' => 1]);
        $this->actingAs($practice->learner);

        return $practice->refresh();
    }

    public function test_teddy_says_another_word_on_a_turn_he_slips_and_the_right_one_on_the_others(): void
    {
        $slips = $this->roundWithSlips([1]);

        $this->assertTrue($slips->teddyIsWrong());
        $this->assertSame('breakfast', $slips->teddyCardLine());

        $fine = $this->roundWithSlips([]);

        $this->assertFalse($fine->teddyIsWrong());
        $this->assertSame('lunch', $fine->teddyCardLine());
    }

    public function test_a_grammar_slip_is_the_wrong_sentence_of_the_card_as_it_stands(): void
    {
        $practice = AiPractice::factory()->create(['stage' => AiPractice::STAGE_GRAMMAR]);
        $practice->update(['deck' => [...$practice->deck, 'slips' => [AiPractice::STAGE_GRAMMAR => [0]]]]);

        $this->assertSame('She go to work.', $practice->refresh()->teddyCardLine());
    }

    public function test_catching_his_slip_scores_and_shows_the_right_answer_once(): void
    {
        $practice = $this->roundWithSlips([1]);

        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->assertSee('Did Teddy get it right?')
            ->assertDontSee('Next')
            ->call('judgeTeddy', 'mistake')
            ->assertSee('You caught it! Teddy slipped.')
            ->assertSee('lunch')
            ->assertSee('Next')
            ->call('judgeTeddy', 'right');

        $practice->refresh();

        $this->assertSame(1, $practice->score);
        $this->assertSame(1, $practice->answered);
        $this->assertSame('mistake', $practice->current['judged']);
    }

    public function test_missing_his_slip_or_doubting_a_right_turn_scores_nothing(): void
    {
        $missed = $this->roundWithSlips([1]);
        Livewire::test('ai-practice.session', ['practice' => $missed])->call('judgeTeddy', 'right')->assertSee('Teddy slipped, and you missed it.');
        $this->assertSame(0, $missed->refresh()->score);

        $doubted = $this->roundWithSlips([]);
        Livewire::test('ai-practice.session', ['practice' => $doubted])->call('judgeTeddy', 'mistake')->assertSee('Teddy was right this time.');
        $this->assertSame(0, $doubted->refresh()->score);

        $right = $this->roundWithSlips([]);
        Livewire::test('ai-practice.session', ['practice' => $right])->call('judgeTeddy', 'right')->assertSee('Right, Teddy had it.');
        $this->assertSame(1, $right->refresh()->score);
    }

    public function test_the_learner_cannot_judge_their_own_turn_or_with_a_made_up_verdict(): void
    {
        $practice = AiPractice::factory()->create();
        $this->actingAs($practice->learner);

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('judgeTeddy', 'right');
        $this->assertNull($practice->refresh()->current);

        $practice->update(['position' => 1]);
        Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])->call('judgeTeddy', 'maybe');
        $this->assertNull($practice->refresh()->current);
    }

    public function test_a_new_round_plans_slips_only_on_teddys_own_turns(): void
    {
        $learner = $this->learnerWithAFinishedDay();

        for ($round = 0; $round < 10; $round++) {
            $practice = app(AiPractices::class)->start($learner, AiPractice::KIND_PRACTICE);

            foreach ($practice->deck['slips'] ?? [] as $stage => $positions) {
                collect($positions)->each(fn (int $position) => $this->assertSame(1, $position % 2, "slip on the learner's own turn in {$stage}"));
            }

            $this->assertContains($practice->level(), [TeddyLevel::EASY, TeddyLevel::NORMAL, TeddyLevel::HARD]);
            $practice->finish();
        }
    }

    public function test_he_plays_easy_after_losses_hard_after_wins_and_normal_with_too_little_to_go_on(): void
    {
        $learner = User::factory()->create();
        $finished = fn (int $score) => AiPractice::factory()->create(['learner_id' => $learner->id, 'score' => $score, 'answered' => 6, 'finished_at' => now()]);
        $level = app(TeddyLevel::class);

        $this->assertSame(TeddyLevel::NORMAL, $level->levelFor($learner, AiPractice::KIND_PRACTICE));

        $finished(1);
        $this->assertSame(TeddyLevel::NORMAL, $level->levelFor($learner, AiPractice::KIND_PRACTICE));

        $finished(1);
        $this->assertSame(TeddyLevel::EASY, $level->levelFor($learner, AiPractice::KIND_PRACTICE));

        $other = User::factory()->create();
        foreach ([8, 7, 8] as $score) {
            AiPractice::factory()->create(['learner_id' => $other->id, 'score' => $score, 'answered' => 8, 'finished_at' => now()]);
        }
        $this->assertSame(TeddyLevel::HARD, $level->levelFor($other, AiPractice::KIND_PRACTICE));
    }

    public function test_six_of_eight_word_cards_are_my_own_words_in_my_turns_first_and_two_are_from_the_lessons(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        foreach (['reluctant', 'grab', 'overwhelmed', 'figure out', 'spicy', 'leftovers', 'recipe'] as $word) {
            VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => $word, 'meaning_fa' => 'معنی '.$word, 'source_mission_run_id' => null]);
        }

        $cards = collect(app(PairPractice::class)->soloDeck($learner)['cards'][AiPractice::STAGE_VOCABULARY]);
        $own = $cards->filter(fn (array $card) => str_starts_with($card['key'], 'w:'));

        $this->assertCount(8, $cards);
        $this->assertCount(6, $own);
        $this->assertCount(2, $cards->reject(fn (array $card) => str_starts_with($card['key'], 'w:')));
        $this->assertTrue($cards->filter(fn (array $card, int $position) => $position % 2 === 0)->every(fn (array $card) => str_starts_with($card['key'], 'w:')));
    }

    public function test_with_no_words_of_my_own_all_eight_cards_come_from_the_lessons(): void
    {
        $learner = $this->learnerWithAFinishedDay();

        $cards = collect(app(PairPractice::class)->soloDeck($learner)['cards'][AiPractice::STAGE_VOCABULARY]);

        $this->assertCount(8, $cards);
        $this->assertSame(0, $cards->filter(fn (array $card) => str_starts_with($card['key'], 'w:'))->count());
    }

    public function test_an_own_word_with_no_persian_meaning_asks_with_its_english_meaning_blanked(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'reluctant', 'meaning_fa' => null, 'meaning' => 'not wanting to do something; reluctant to start', 'source_mission_run_id' => null]);
        VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => 'ghost', 'meaning_fa' => null, 'meaning' => null, 'example' => null, 'source_mission_run_id' => null]);

        $cards = collect(app(PairPractice::class)->soloDeck($learner)['cards'][AiPractice::STAGE_VOCABULARY]);
        $card = $cards->firstWhere('answer', 'reluctant');

        $this->assertSame('en', $card['prompt_lang']);
        $this->assertSame('not wanting to do something; _____ to start', $card['prompt']);
        $this->assertNull($cards->firstWhere('answer', 'ghost'));
    }

    public function test_a_word_card_gives_hints_one_at_a_time_without_the_word(): void
    {
        $hints = AiPractice::wordHints(['answer' => 'lunch', 'meaning' => 'the midday meal, lunch', 'example' => 'We have lunch at noon.']);

        $this->assertSame('the midday meal, _____', $hints[0]);
        $this->assertSame('Starts with “l” · 5 letters · We have _____ at noon.', $hints[1]);
        $this->assertCount(1, AiPractice::wordHints(['answer' => 'lunch']));
    }

    public function test_a_missed_card_with_an_english_prompt_is_saved_without_a_persian_meaning(): void
    {
        $practice = AiPractice::factory()->create(['answered' => 3]);
        $practice->update(['deck' => [...$practice->deck, 'missed_words' => [['prompt' => 'not wanting to _____', 'prompt_lang' => 'en', 'answer' => 'reluctant']]]]);

        $practice->refresh()->finish();

        $this->assertNull(VocabularyWord::where('word', 'reluctant')->value('meaning_fa'));
    }

    public function test_who_am_i_a_question_i_already_asked_is_not_counted_and_not_sent_to_the_ai(): void
    {
        $practice = AiPractice::factory()->create(['kind' => PairGame::GAME_WHO, 'stage' => AiPractice::STAGE_GAME, 'position' => 0, 'deck' => ['game' => ['game' => PairGame::GAME_WHO, 'cards' => [['word' => 'cat', 'category' => 'an animal']]]]]);
        $practice->update(['current' => ['qa' => [['q' => 'Do you have four legs?', 'a' => 'yes']]]]);
        $this->actingAs($practice->learner);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn('do you have four legs'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldNotReceive('chat'));

        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))
            ->call('submitAnswer')
            ->assertSee('You already asked that: yes. Ask something new.');

        $this->assertCount(1, $practice->refresh()->current['qa']);
        $this->assertSame(0, $practice->answered);
    }

    public function test_who_am_i_teddy_never_asks_the_same_thing_twice_and_asks_again_when_the_ai_repeats(): void
    {
        $practice = AiPractice::factory()->create(['kind' => PairGame::GAME_WHO, 'stage' => AiPractice::STAGE_GAME, 'position' => 1, 'deck' => ['game' => ['game' => PairGame::GAME_WHO, 'cards' => [['word' => 'x', 'category' => 'a job'], ['word' => 'teacher', 'category' => 'a job']]]]]);
        $practice->update(['current' => ['qa' => [['q' => 'Do you work in a school?', 'a' => 'yes']]]]);
        $this->actingAs($practice->learner);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn('{"question":"Do you work in a school"}', '{"question":"Do you teach children?"}'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiAsks')->assertSee('Do you teach children?');

        $this->assertSame('Do you teach children?', collect($practice->refresh()->current['qa'])->last()['q']);
    }

    public function test_who_am_i_teddy_falls_back_to_a_new_plain_question_when_the_ai_only_repeats(): void
    {
        $practice = AiPractice::factory()->create(['kind' => PairGame::GAME_WHO, 'stage' => AiPractice::STAGE_GAME, 'position' => 1, 'deck' => ['game' => ['game' => PairGame::GAME_WHO, 'cards' => [['word' => 'x', 'category' => 'a job'], ['word' => 'teacher', 'category' => 'a job']]]]]);
        $practice->update(['current' => ['qa' => [['q' => 'Are you alive?', 'a' => 'yes']]]]);
        $this->actingAs($practice->learner);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"question":"Are you alive?"}'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiAsks');

        $asked = collect($practice->refresh()->current['qa'])->pluck('q');

        $this->assertCount(2, $asked);
        $this->assertSame(2, $asked->unique()->count());
        $this->assertNotSame('Are you alive?', $asked->last());
    }

    public function test_the_same_question_is_found_in_other_words_order_and_punctuation(): void
    {
        $this->assertTrue(AiPractice::sameQuestion('Are you big?', 'are you BIG'));
        $this->assertTrue(AiPractice::sameQuestion('big, are you', 'Are you big?'));
        $this->assertFalse(AiPractice::sameQuestion('Are you big?', 'Are you small?'));
    }

    public function test_teddys_page_starts_a_round_from_the_days_i_pick(): void
    {
        $learner = $this->learnerWithAFinishedDay();
        $this->actingAs($learner);

        Livewire::test('friends.teddy')->assertSee('Your latest days')->call('start', AiPractice::KIND_PRACTICE, ['M01-1'])->assertRedirect();

        $this->assertSame(['M01-1'], AiPractice::where('learner_id', $learner->id)->latest('id')->first()->days);
    }
}
