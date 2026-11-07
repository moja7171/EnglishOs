<?php

namespace Tests\Feature;

use App\Models\PairGame;
use App\Models\User;
use App\Services\GameBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GameBadgesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A finished game between $a and $b, played for $seconds from its
     * first turn.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finishedGame(User $a, User $b, array $attributes = [], int $seconds = 300): PairGame
    {
        [$low, $high] = $a->id < $b->id ? [$a, $b] : [$b, $a];
        $finishedAt = $attributes['finished_at'] ?? now();

        return PairGame::factory()->create([
            'user_a_id' => $low->id,
            'user_b_id' => $high->id,
            'started_by_id' => $a->id,
            'stage' => PairGame::STAGE_DONE,
            'score' => 2,
            'turns' => [['explainer_id' => $a->id, 'started_at' => $finishedAt->getTimestamp() - $seconds, 'ends_at' => $finishedAt->getTimestamp(), 'cards' => []]],
            ...$attributes,
            'finished_at' => $finishedAt,
        ]);
    }

    /**
     * @return array<string, array{progress: int, earned: bool}>
     */
    private function badgesOf(User $user): array
    {
        return collect(app(GameBadges::class)->for($user))->keyBy('key')->all();
    }

    public function test_the_first_finished_game_earns_a_badge_shown_as_new_only_on_its_result(): void
    {
        [$me, $friend] = User::factory()->count(2)->create();
        $first = $this->finishedGame($me, $friend, ['finished_at' => now()->subHour()]);

        $this->actingAs($me);
        Livewire::test('games.play', ['pairGame' => $first])->assertSee('NEW BADGE')->assertSee('First game');

        $second = $this->finishedGame($me, $friend, ['score' => 1]);
        $this->assertNotContains('first-game', collect(app(GameBadges::class)->newWith($me, $second))->pluck('key'));
        $this->assertContains('first-game', collect(app(GameBadges::class)->newWith($me, $first))->pluck('key'), 'Still new as of its own result.');

        $this->get(route('games.index'))->assertOk()->assertSee('Your badges')->assertSee('Record breaker');
    }

    public function test_variety_count_and_records_come_from_finished_games_only(): void
    {
        [$me, $friend] = User::factory()->count(2)->create();

        foreach (PairGame::PLAYABLE as $index => $game) {
            $this->finishedGame($me, $friend, ['game' => $game, 'finished_at' => now()->subMinutes(30 - $index)]);
        }
        // Same game and length, not better: no record.
        $this->finishedGame($me, $friend, ['game' => PairGame::GAME_TABOO, 'score' => 1]);
        PairGame::factory()->create(['user_a_id' => $me->id, 'user_b_id' => $friend->id, 'stage' => PairGame::STAGE_CANCELLED]);

        $badges = $this->badgesOf($me);
        $this->assertTrue($badges['all-games']['earned']);
        // One finished game of each playable game, and one more of Taboo.
        $this->assertSame(count(PairGame::PLAYABLE) + 1, $badges['games-10']['progress']);
        $this->assertTrue($badges['records-5']['earned']);
        $this->assertFalse($badges['games-10']['earned']);
    }

    public function test_three_days_running_with_the_same_friend_earns_the_streak(): void
    {
        [$me, $sara, $ali] = User::factory()->count(3)->create();
        $this->finishedGame($me, $sara, ['finished_at' => now()->subDays(2)]);
        $this->finishedGame($me, $ali, ['finished_at' => now()->subDay()]);
        $this->finishedGame($me, $sara, ['finished_at' => now()]);

        $this->assertSame(1, $this->badgesOf($me)['friend-streak']['progress']);

        $this->finishedGame($me, $sara, ['finished_at' => now()->subDay()]);
        $this->assertTrue($this->badgesOf($me)['friend-streak']['earned']);
    }

    public function test_bonus_words_avoided_count_only_my_guessed_taboo_cards_and_minutes_are_capped_by_the_game_length(): void
    {
        [$me, $friend] = User::factory()->count(2)->create();
        $this->finishedGame($me, $friend, [
            'minutes' => 5,
            'turns' => [
                ['explainer_id' => $me->id, 'started_at' => now()->getTimestamp() - 3600, 'ends_at' => now()->getTimestamp(), 'cards' => [
                    ['card' => 0, 'result' => PairGame::RESULT_GOT, 'bonus_said' => false],
                    ['card' => 1, 'result' => PairGame::RESULT_GOT, 'bonus_said' => false],
                    ['card' => 2, 'result' => PairGame::RESULT_GOT, 'bonus_said' => true],
                ]],
                ['explainer_id' => $friend->id, 'started_at' => now()->getTimestamp(), 'ends_at' => now()->getTimestamp(), 'cards' => [
                    ['card' => 0, 'result' => PairGame::RESULT_GOT, 'bonus_said' => false],
                ]],
            ],
        ]);

        $badges = $this->badgesOf($me);
        // Card 0 has bonus words; card 1 has none; card 2's were said.
        $this->assertSame(1, $badges['bonus-10']['progress']);
        $this->assertSame(5, $badges['talk-60']['progress']);
    }
}
