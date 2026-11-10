<?php

namespace Tests\Feature;

use App\Models\PairGame;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Personal points in the four competitive games (Ask & Answer, Two Truths,
 * Just a Minute, Who am I) and the Board's Game points tab.
 */
class CompetitiveGamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $cards
     */
    private function finishedGame(string $game, User $a, User $b, array $cards, int $explainerId, ?User $inFinishedAt = null): PairGame
    {
        return PairGame::factory()->create([
            'user_a_id' => $a->id,
            'user_b_id' => $b->id,
            'started_by_id' => $a->id,
            'game' => $game,
            'stage' => PairGame::STAGE_DONE,
            'finished_at' => now(),
            'turns' => [['explainer_id' => $explainerId, 'cards' => $cards]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $result, int $points, array $extra = []): array
    {
        return ['card' => 0, 'result' => $result, 'letter' => false, 'bonus_said' => false, 'questions' => 0, 'heard' => [], 'guesses' => [], 'lie' => null, 'picks' => [], 'guess' => null, 'qa' => [], 'points' => $points, 'at' => now()->getTimestamp(), ...$extra];
    }

    public function test_only_the_four_objective_games_are_competitive(): void
    {
        foreach ([PairGame::GAME_ASK, PairGame::GAME_TRUTHS, PairGame::GAME_MINUTE, PairGame::GAME_WHO] as $game) {
            $this->assertTrue(PairGame::factory()->make(['game' => $game])->isCompetitive(), $game);
        }

        foreach ([PairGame::GAME_TABOO, PairGame::GAME_STORY, PairGame::GAME_ROLE, PairGame::GAME_RATHER] as $game) {
            $this->assertFalse(PairGame::factory()->make(['game' => $game])->isCompetitive(), $game);
        }
    }

    public function test_ask_and_answer_gives_each_player_their_own_sentences_times_the_level(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $qa = [
            'question' => ['points' => 3, 'level' => 3, 'status' => 'ok'],
            'answer' => ['points' => 2, 'level' => 1, 'status' => 'ok'],
        ];
        $game = $this->finishedGame(PairGame::GAME_ASK, $a, $b, [$this->card('got', 5, ['qa' => $qa])], $a->id);

        // A asked at level 3 (x2 of 3 half points), B answered at level 1 (x1 of 2).
        $this->assertSame([$a->id => 6, $b->id => 2], $game->personalPoints());

        $second = $this->finishedGame(PairGame::GAME_ASK, $a, $b, [$this->card('got', 1, ['qa' => ['question' => ['points' => 1, 'level' => 2, 'status' => 'fixed']]])], $a->id);

        // One half point at level 2 is 1.5, rounded to 2 half points.
        $this->assertSame(2, $second->personalPoints()[$a->id]);
    }

    public function test_two_truths_pays_the_listener_who_found_the_lie_and_nobody_else(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $game = $this->finishedGame(PairGame::GAME_TRUTHS, $a, $b, [$this->card('got', 2), $this->card('missed', 0)], $a->id);

        $this->assertSame([$a->id => 0, $b->id => 2], $game->personalPoints());
    }

    public function test_just_a_minute_pays_the_talker_for_heard_words_and_the_listener_for_the_question(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $game = $this->finishedGame(PairGame::GAME_MINUTE, $a, $b, [$this->card('got', 8, ['heard' => ['one', 'two', 'three']])], $a->id);

        $this->assertSame([$a->id => 6, $b->id => 2], $game->personalPoints());
    }

    public function test_who_am_i_pays_the_guesser_and_quicker_guesses_are_worth_more(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $game = $this->finishedGame(PairGame::GAME_WHO, $a, $b, [$this->card('got', 4, ['questions' => 3]), $this->card('got', 2, ['questions' => 8]), $this->card('missed', 0, ['questions' => 10])], $a->id);

        $this->assertSame([$a->id => 0, $b->id => 6], $game->personalPoints());
    }

    public function test_a_cooperative_game_gives_no_personal_points(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $game = $this->finishedGame(PairGame::GAME_TABOO, $a, $b, [$this->card('got', 2)], $a->id);

        $this->assertSame([$a->id => 0, $b->id => 0], $game->personalPoints());
    }

    public function test_the_board_ranks_by_game_points_on_its_own_tab(): void
    {
        $me = User::factory()->create(['name' => 'Me Myself']);
        $friend = User::factory()->create(['name' => 'Fast Fiona']);
        $me->follow($friend);
        $friend->acceptFollowRequest($me);
        $this->actingAs($me);

        // Fiona found one lie (2 half points); I found three (6).
        $this->finishedGame(PairGame::GAME_TRUTHS, $me, $friend, [$this->card('got', 2)], $me->id);
        $this->finishedGame(PairGame::GAME_TRUTHS, $me, $friend, [$this->card('got', 2), $this->card('got', 2), $this->card('got', 2)], $friend->id);
        $this->finishedGame(PairGame::GAME_TRUTHS, $me, $friend, [$this->card('got', 2)], $friend->id);
        // A cooperative game and one from a pair-practice round add nothing.
        $this->finishedGame(PairGame::GAME_TABOO, $me, $friend, [$this->card('got', 2)], $me->id);

        $component = Livewire::test('friends.board')->call('rankBy', 'games');

        $ranked = $component->instance()->ranked();
        $this->assertSame([$me->id, $friend->id], collect($ranked)->pluck('user.id')->all());
        $this->assertSame([8, 2], collect($ranked)->pluck('games')->all());
        $component->assertSee('Game points')->assertSee('pts');
    }

    public function test_the_result_and_the_arena_show_the_personal_points_of_a_competitive_game_only(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $this->actingAs($a);
        $game = $this->finishedGame(PairGame::GAME_TRUTHS, $a, $b, [$this->card('got', 2)], $b->id);

        $this->assertSame(2, $game->personalPoints()[$a->id]);

        $html = view('components.games.partials.result', ['pairGame' => $game, 'me' => $a, 'friend' => $b, 'best' => null])->render();
        $this->assertStringContainsString('1 pts', $html);

        $taboo = $this->finishedGame(PairGame::GAME_TABOO, $a, $b, [$this->card('got', 2)], $b->id);
        $this->assertStringNotContainsString(' pts', view('components.games.partials.result', ['pairGame' => $taboo, 'me' => $a, 'friend' => $b, 'best' => null])->render());
    }
}
