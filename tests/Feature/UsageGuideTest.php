<?php

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\PairGame;
use App\Models\User;
use App\Services\UsageGuides;
use Database\Seeders\MissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one-time guide of each part of the training: see
 * App\Services\UsageGuides and resources/views/components/⚡usage-guide.blade.php.
 */
class UsageGuideTest extends TestCase
{
    use RefreshDatabase;

    private const OPENS_BY_ITSELF = 'open: true, confirmRead';

    public function test_every_step_of_every_mission_and_every_game_has_a_short_guide(): void
    {
        $this->seed(MissionSeeder::class);

        $keys = Mission::all()
            ->flatMap(fn (Mission $mission) => $mission->stepKeys())
            ->unique()
            ->map(fn (string $stepKey) => UsageGuides::keyForStep($stepKey))
            ->merge(array_map(UsageGuides::keyForGame(...), PairGame::PLAYABLE));

        foreach ($keys as $key) {
            $guide = UsageGuides::find($key);

            $this->assertNotNull($guide, $key.' has no guide.');
            $this->assertNotEmpty($guide['title']);
            $this->assertLessThanOrEqual(3, count($guide['lines']), $key.' is too long.');
        }
    }

    public function test_a_guide_keeps_opening_until_it_is_confirmed_and_then_stays_closed(): void
    {
        $learner = User::factory()->create();
        $this->actingAs($learner);

        Livewire::test('usage-guide', ['guide' => 'notebook'])->assertSet('opensByItself', true);
        $component = Livewire::test('usage-guide', ['guide' => 'notebook'])->assertSet('opensByItself', true);

        $component->call('markRead');

        $this->assertSame(['notebook'], $learner->fresh()->read_guides);
        Livewire::test('usage-guide', ['guide' => 'notebook'])->assertSet('opensByItself', false);
    }

    public function test_confirming_one_guide_leaves_other_guides_and_other_learners_alone(): void
    {
        $learner = User::factory()->create();
        $learner->markGuideRead('notebook');
        $learner->markGuideRead('notebook');

        $this->assertSame(['notebook'], $learner->fresh()->read_guides);

        $this->actingAs($learner);
        Livewire::test('usage-guide', ['guide' => 'review'])->assertSet('opensByItself', true);

        $this->actingAs(User::factory()->create());
        Livewire::test('usage-guide', ['guide' => 'notebook'])->assertSet('opensByItself', true);
    }

    public function test_a_step_on_several_days_shares_one_guide_but_the_two_talks_do_not(): void
    {
        $this->assertSame(UsageGuides::keyForStep('vocabulary_builder_1'), UsageGuides::keyForStep('vocabulary_builder_3'));
        $this->assertSame(UsageGuides::keyForStep('daily_listen_2'), UsageGuides::keyForStep('daily_listen_3'));
        $this->assertNotSame(UsageGuides::keyForStep('ai_conversation_1'), UsageGuides::keyForStep('ai_conversation_2'));
    }

    public function test_a_guide_marked_to_wait_never_opens_by_itself(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('usage-guide', ['guide' => 'notebook', 'auto' => false])->assertSet('opensByItself', false);
    }

    public function test_a_live_step_opens_its_guide_and_it_stays_closed_once_read(): void
    {
        $run = $this->freshM01Run();
        $firstStep = $run->mission->stepKeys()[0];
        $url = route('missions.show', [$run->mission, $firstStep]);

        $this->get($url)->assertOk()->assertSeeHtml(self::OPENS_BY_ITSELF);

        auth()->user()->markGuideRead(UsageGuides::keyForStep($firstStep));

        $this->get($url)->assertOk()->assertDontSeeHtml(self::OPENS_BY_ITSELF);
    }

    public function test_a_finished_step_and_a_preview_only_offer_their_guide(): void
    {
        $run = $this->freshM01Run();
        $firstStep = $run->mission->stepKeys()[0];
        Evidence::create(['mission_run_id' => $run->id, 'phase' => $firstStep, 'type' => Evidence::TYPE_SCORE, 'content_ref' => '3']);

        $this->get(route('missions.show', [$run->mission, $firstStep]))
            ->assertOk()
            ->assertSeeHtml('aria-label="How to use this"')
            ->assertSee(UsageGuides::find(UsageGuides::keyForStep($firstStep))['lines'][0])
            ->assertDontSeeHtml(self::OPENS_BY_ITSELF);

        $this->get(route('missions.show', [$run->mission, 'grammar_in_context']))
            ->assertOk()
            ->assertSeeHtml('aria-label="How to use this"')
            ->assertSee(UsageGuides::find('grammar_in_context')['lines'][0])
            ->assertDontSeeHtml(self::OPENS_BY_ITSELF);
    }

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function sections(): array
    {
        return [
            'review' => ['review.index', [], 'review'],
            'notebook' => ['notebook.index', [], 'notebook'],
            'listening' => ['listening.show', [], 'listening_page'],
            'daily talk' => ['pi.practice', [], 'daily_talk'],
            'pair practice' => ['pair-practice.index', [], 'pair_practice'],
        ];
    }

    /**
     * @param  array<string, string>  $parameters
     */
    #[DataProvider('sections')]
    public function test_each_section_opens_its_guide_and_keeps_it_behind_the_button_once_read(string $route, array $parameters, string $guide): void
    {
        $this->freshM01Run();

        $this->get(route($route, $parameters))
            ->assertOk()
            ->assertSee(UsageGuides::find($guide)['lines'][0])
            ->assertSeeHtml(self::OPENS_BY_ITSELF);

        auth()->user()->markGuideRead($guide);

        $this->get(route($route, $parameters))
            ->assertOk()
            ->assertSeeHtml('aria-label="How to use this"')
            ->assertDontSeeHtml(self::OPENS_BY_ITSELF);
    }

    public function test_a_review_path_opened_directly_opens_the_review_guide(): void
    {
        $this->freshM01Run();

        $this->get(route('review.track', 'words'))
            ->assertOk()
            ->assertSeeHtml(self::OPENS_BY_ITSELF);
    }

    public function test_home_opens_the_mission_guide(): void
    {
        $this->freshM01Run();

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(UsageGuides::find('mission')['lines'][0])
            ->assertSeeHtml(self::OPENS_BY_ITSELF);
    }

    public function test_an_invited_friend_gets_the_game_guide_on_the_invite_but_never_over_a_running_turn(): void
    {
        $pairGame = PairGame::factory()->create(['stage' => PairGame::STAGE_WAITING, 'joined_at' => null]);
        $friend = $pairGame->partnerFor($pairGame->startedBy);

        $this->actingAs($friend)
            ->get(route('games.play', $pairGame))
            ->assertOk()
            ->assertSee(PairGame::guide($pairGame->game)[0])
            ->assertSeeHtml(self::OPENS_BY_ITSELF);

        $pairGame->join($friend);
        $pairGame->startTurn($pairGame->refresh()->explainer());

        $this->actingAs($friend)
            ->get(route('games.play', $pairGame))
            ->assertOk()
            ->assertSee(PairGame::guide($pairGame->game)[0])
            ->assertDontSeeHtml(self::OPENS_BY_ITSELF);
    }

    private function freshM01Run(): MissionRun
    {
        $this->seed(MissionSeeder::class);

        $learner = User::factory()->create();
        $learner->placementTests()->create(['level' => 'A2', 'recognition_level' => 'A2', 'detail' => []]);
        $this->actingAs($learner);

        return MissionRun::findOrStart($learner, Mission::where('code', 'M01')->firstOrFail());
    }
}
