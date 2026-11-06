<?php

namespace Tests\Feature;

use App\Models\AiPractice;
use App\Models\PairGame;
use App\Models\VocabularyWord;
use App\Services\AiPractices;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use App\Services\TeddyLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Teddy's slips in the speaking cards, Who am I, Just a Minute and Story
 * Chain, and the learner's own words in the games.
 */
class TeddyMakesMistakesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $cards
     * @param  list<int>  $slips
     * @param  array<string, mixed>|null  $current
     */
    private function gameWithSlips(string $game, array $cards, array $slips, int $position = 0, ?array $current = null): AiPractice
    {
        $practice = AiPractice::factory()->create([
            'kind' => $game,
            'deck' => ['game' => ['game' => $game, 'cards' => $cards], 'slips' => ['game' => $slips]],
            'stage' => AiPractice::STAGE_GAME,
            'position' => $position,
            'current' => $current,
        ]);
        $this->actingAs($practice->learner);

        return $practice;
    }

    private function speakingRound(array $slips): AiPractice
    {
        $practice = AiPractice::factory()->create(['stage' => AiPractice::STAGE_SPEAKING, 'position' => 1]);
        $practice->update(['deck' => [
            ...$practice->deck,
            AiPractice::STAGE_SPEAKING => [
                ['question' => 'What time do you wake up?', 'answer' => 'I wake up at seven.', 'words' => [], 'source' => 'M01', 'key' => 's:1'],
                ['question' => 'Where do you work?', 'answer' => 'I work in a bank.', 'words' => [], 'source' => 'M01', 'key' => 's:2'],
            ],
            'slips' => [AiPractice::STAGE_SPEAKING => $slips],
        ]]);
        $this->actingAs($practice->learner);

        return $practice->refresh();
    }

    public function test_a_speaking_card_he_slips_on_gets_an_answer_with_one_mistake_the_learner_can_catch(): void
    {
        $practice = $this->speakingRound([1]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('I work in bank.'));

        Livewire::test('ai-practice.session', ['practice' => $practice])
            ->assertSee('I work in bank.')
            ->call('judgeTeddy', 'mistake')
            ->assertSee('You caught it! Teddy slipped.')
            ->assertSee('I work in a bank.');

        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_a_speaking_slip_the_ai_cannot_write_just_means_he_says_the_right_answer(): void
    {
        $practice = $this->speakingRound([1]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andThrow(new RuntimeException('down')));

        Livewire::test('ai-practice.session', ['practice' => $practice])->assertSee('I work in a bank.');

        $practice->refresh();

        $this->assertFalse($practice->teddyIsWrong());
        $this->assertNotContains(1, $practice->deck['slips'][AiPractice::STAGE_SPEAKING]);
    }

    public function test_who_am_i_teddy_gives_one_answer_the_wrong_way_round_and_the_learner_catches_it(): void
    {
        $practice = $this->gameWithSlips(PairGame::GAME_WHO, [['word' => 'cat', 'category' => 'an animal']], [0], 0, ['qa' => [['q' => 'Is it big?', 'a' => 'no']]]);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn('Does it have four legs?'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"answer":"yes","guessed":false}'));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice])
            ->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))
            ->call('submitAnswer');

        $row = $practice->refresh()->current['qa'][1];

        $this->assertSame('no', $row['a']);
        $this->assertSame('yes', $row['real']);
        $this->assertTrue($row['flip']);

        $component->call('challengeAnswer', 0)->call('challengeAnswer', 1)->call('challengeAnswer', 1);

        $this->assertSame(1, $practice->refresh()->score, 'only the real slip scores, and only once');
    }

    public function test_who_am_i_teddy_never_slips_on_the_first_question_or_the_winning_one(): void
    {
        $first = $this->gameWithSlips(PairGame::GAME_WHO, [['word' => 'cat', 'category' => 'an animal']], [0]);
        $this->mock(GroqClient::class, fn ($mock) => $mock->shouldReceive('transcribe')->andReturn('Is it a cat?'));
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->andReturn('{"answer":"yes","guessed":true}'));

        Livewire::test('ai-practice.session', ['practice' => $first])->set('audioFile', UploadedFile::fake()->create('answer.webm', 40, 'audio/webm'))->call('submitAnswer');

        $this->assertArrayNotHasKey('flip', $first->refresh()->current['qa'][0]);
        $this->assertSame('yes', $first->current['qa'][0]['a']);
    }

    public function test_just_a_minute_his_talk_has_one_sentence_with_a_mistake_to_find(): void
    {
        $practice = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'My day', 'words' => ['coffee', 'bus']], ['word' => 'My town', 'words' => ['coffee', 'bus']]], [1], 1);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"sentences":["I drink coffee.","She go by bus.","I like my town."],"slip":1,"fix":"She goes by bus."}'));

        $component = Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiTalks')->assertSee('She go by bus.');

        $this->assertSame(1, $practice->refresh()->current['slip']['slip']);

        $component->call('pickTalkSlip', 0)->call('pickTalkSlip', 1);
        $this->assertSame(0, $practice->refresh()->score, 'a wrong tap is final');

        $other = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'x', 'words' => ['a', 'b']], ['word' => 'y', 'words' => ['coffee', 'bus']]], [1], 1, [
            'talk' => 'I drink coffee. She go by bus. I like my town.', 'used' => ['coffee', 'bus'], 'ticked' => [],
            'slip' => ['sentences' => ['I drink coffee.', 'She go by bus.', 'I like my town.'], 'slip' => 1, 'fix' => 'She goes by bus.'],
        ]);
        Livewire::test('ai-practice.session', ['practice' => $other])->call('pickTalkSlip', 1)->assertSee('You found it!')->assertSee('She goes by bus.');
        $this->assertSame(1, $other->refresh()->score);
    }

    public function test_just_a_minute_the_learner_is_always_asked_so_a_talk_with_no_mistake_is_not_given_away(): void
    {
        $current = ['talk' => 'I drink coffee. I take the bus. I like my town.', 'used' => ['coffee', 'bus'], 'ticked' => []];
        $right = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'x', 'words' => ['a', 'b']], ['word' => 'y', 'words' => ['coffee', 'bus']]], [], 1, $current);

        Livewire::test('ai-practice.session', ['practice' => $right])
            ->assertSee('Is there a mistake? Tap the sentence, or say there isn’t one.')
            ->assertSee('No mistake')
            ->assertSee('I take the bus.')
            ->call('pickTalkSlip', -1)
            ->assertSee('✓ Right, no mistake.')
            ->call('pickTalkSlip', 0);

        $this->assertSame(1, $right->refresh()->score, 'one call, scored once');

        $wrong = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'x', 'words' => ['a', 'b']], ['word' => 'y', 'words' => ['coffee', 'bus']]], [], 1, $current);
        Livewire::test('ai-practice.session', ['practice' => $wrong])->call('pickTalkSlip', 1)->assertSee('There was no mistake this time.');
        $this->assertSame(0, $wrong->refresh()->score);

        $missed = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'x', 'words' => ['a', 'b']], ['word' => 'y', 'words' => ['coffee', 'bus']]], [1], 1, [
            ...$current, 'slip' => ['sentences' => ['I drink coffee.', 'She go by bus.', 'I like my town.'], 'slip' => 1, 'fix' => 'She goes by bus.'],
        ]);
        Livewire::test('ai-practice.session', ['practice' => $missed])->call('pickTalkSlip', -1)->assertSee('Not that one.');
        $this->assertSame(0, $missed->refresh()->score);
    }

    public function test_story_chain_the_learner_is_always_asked_and_no_mistake_is_right_when_there_was_none(): void
    {
        $lines = [['who' => 'you', 'text' => 'He walked home.'], ['who' => 'teddy', 'text' => 'Then he saw a dog.']];
        $card = ['word' => 'dog', 'story' => 0, 'line' => 'One day, a boy found a map.', 'source' => 'M01', 'bonus' => [], 'key' => 'story:1'];

        $clean = $this->gameWithSlips(PairGame::GAME_STORY, [$card, $card], [], 1, ['lines' => $lines, 'ended' => true]);
        Livewire::test('ai-practice.session', ['practice' => $clean])
            ->assertSee('Did Teddy make a mistake? Tap his line, or say there wasn’t one.')
            ->call('pickStorySlip', 1)
            ->assertSee('There was no mistake this time.');
        $this->assertSame(0, $clean->refresh()->score);

        $clean2 = $this->gameWithSlips(PairGame::GAME_STORY, [$card, $card], [], 1, ['lines' => $lines, 'ended' => true]);
        Livewire::test('ai-practice.session', ['practice' => $clean2])->call('pickStorySlip', -1)->assertSee('✓ Right, no mistake.');
        $this->assertSame(1, $clean2->refresh()->score);

        $slipped = $this->gameWithSlips(PairGame::GAME_STORY, [$card, $card], [], 1, ['lines' => [$lines[0], [...$lines[1], 'fix' => 'Then he saw a dog.']], 'ended' => true]);
        Livewire::test('ai-practice.session', ['practice' => $slipped])->call('pickStorySlip', -1)->assertSee('There was a slip. It is marked in green.');
        $this->assertSame(0, $slipped->refresh()->score);
    }

    public function test_just_a_minute_a_slip_the_ai_cannot_write_falls_back_to_a_plain_talk(): void
    {
        $practice = $this->gameWithSlips(PairGame::GAME_MINUTE, [['word' => 'x', 'words' => ['a', 'b']], ['word' => 'My day', 'words' => ['coffee', 'bus']]], [1], 1);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->twice()->andReturn('not json', 'I drink coffee and take the bus.'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('aiTalks');

        $this->assertArrayNotHasKey('slip', $practice->refresh()->current);
        $this->assertSame('I drink coffee and take the bus.', $practice->current['talk']);
    }

    public function test_story_chain_one_of_his_lines_has_a_slip_found_at_the_end(): void
    {
        $cards = collect(range(1, 4))->map(fn (int $turn) => ['word' => 'word'.$turn, 'story' => 0, 'line' => 'One day, a boy found a map.', 'source' => 'M01', 'bonus' => [], 'key' => 'story:'.$turn])->all();
        $practice = $this->gameWithSlips(PairGame::GAME_STORY, $cards, [0], 0, ['lines' => [['who' => 'you', 'text' => 'He walked home.']]]);
        $this->mock(GeminiClient::class, fn ($mock) => $mock->shouldReceive('chat')->once()->andReturn('{"line":"Then he see a dog.","fix":"Then he saw a dog."}'));

        Livewire::test('ai-practice.session', ['practice' => $practice])->call('next');

        $lines = $practice->refresh()->current['lines'];

        $this->assertSame('Then he see a dog.', $lines[1]['text']);
        $this->assertSame('Then he saw a dog.', $lines[1]['fix']);

        $practice->update(['current' => [...$practice->current, 'ended' => true]]);
        $component = Livewire::test('ai-practice.session', ['practice' => $practice->refresh()])->assertSee('Did Teddy make a mistake? Tap his line, or say there wasn’t one.');

        $component->call('pickStorySlip', 0)->assertSet('practice.current.slip_pick', null);
        $component->call('pickStorySlip', 1)->assertSee('You found Teddy’s slip!')->assertSee('Better: Then he saw a dog.');

        $this->assertSame(1, $practice->refresh()->score);
    }

    public function test_the_levels_slip_chances_plan_game_turns_for_the_three_games_that_have_slips(): void
    {
        $level = app(TeddyLevel::class);
        $who = ['game' => ['game' => PairGame::GAME_WHO, 'cards' => array_fill(0, 6, ['word' => 'x'])]];
        $jam = ['game' => ['game' => PairGame::GAME_MINUTE, 'cards' => array_fill(0, 4, ['word' => 'x'])]];
        $taboo = ['game' => ['game' => PairGame::GAME_TABOO, 'cards' => array_fill(0, 4, ['word' => 'x'])]];

        for ($try = 0; $try < 30; $try++) {
            collect($level->planSlips($who, 'hard')['game'] ?? [])->each(fn (int $position) => $this->assertSame(0, $position % 2));
            collect($level->planSlips($jam, 'hard')['game'] ?? [])->each(fn (int $position) => $this->assertSame(1, $position % 2));
            $this->assertArrayNotHasKey('game', $level->planSlips($taboo, 'hard'));
        }
    }

    public function test_my_own_words_go_on_my_cards_in_story_chain_taboo_and_just_a_minute(): void
    {
        $practice = AiPractice::factory()->create();
        $learner = $practice->learner;
        foreach (['reluctant', 'grab', 'overwhelmed'] as $word) {
            VocabularyWord::factory()->create(['learner_id' => $learner->id, 'word' => $word, 'source_mission_run_id' => null]);
        }
        $service = app(AiPractices::class);

        $story = $service->withOwnWordsInGame($learner, PairGame::GAME_STORY, array_map(fn (int $i) => ['word' => 'tree'.$i, 'bonus' => [], 'key' => 'k'.$i, 'source' => 'M01'], range(0, 3)));
        $this->assertSame(['My Words', 'M01', 'My Words', 'M01'], array_column($story, 'source'));
        $this->assertSame('tree1', $story[1]['word']);

        $taboo = $service->withOwnWordsInGame($learner, PairGame::GAME_TABOO, array_map(fn (int $i) => ['word' => 'tree'.$i, 'bonus' => [], 'key' => 'k'.$i, 'source' => 'M01'], range(0, 3)));
        $this->assertCount(2, array_filter($taboo, fn (array $card) => $card['source'] === 'My Words'));

        $jam = $service->withOwnWordsInGame($learner, PairGame::GAME_MINUTE, [['word' => 'My day', 'words' => ['coffee', 'bus', 'tea']], ['word' => 'My town', 'words' => ['a', 'b']]]);
        $this->assertSame(['coffee', 'bus', $jam[0]['words'][2]], $jam[0]['words']);
        $this->assertContains($jam[0]['words'][2], ['reluctant', 'grab', 'overwhelmed']);
        $this->assertSame(['a', 'b'], $jam[1]['words']);

        $this->assertSame([['word' => 'x']], $service->withOwnWordsInGame($learner, PairGame::GAME_WHO, [['word' => 'x']]));
    }
}
