<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Evidence;
use App\Models\Mission;
use App\Models\MissionRun;
use App\Models\StepDraft;
use App\Models\User;
use App\Notifications\AdminMessage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * /admin and /admin/users/{user}: admins only, and what an admin can do
 * to a learner from there.
 */
class AdminPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.index'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'admin page' => ['admin.index'],
            'one learner' => ['admin.users.show'],
        ];
    }

    #[DataProvider('adminRoutes')]
    public function test_a_learner_who_is_not_an_admin_gets_not_found(string $routeName): void
    {
        $learner = User::factory()->create();

        $this->actingAs($learner)->get(route($routeName, $routeName === 'admin.users.show' ? [$learner] : []))->assertNotFound();
    }

    public function test_a_learner_who_is_not_an_admin_cannot_call_the_admin_actions(): void
    {
        $learner = User::factory()->create();

        Livewire::actingAs($learner)->test('admin.user', ['user' => $learner])->assertNotFound();
    }

    public function test_an_admin_sees_the_dashboard_and_the_menu_link(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['last_seen_at' => now()])->save();

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('Active users')
            ->assertSee('Needs attention')
            ->assertSee(route('admin.index'));
    }

    public function test_the_admin_link_is_hidden_from_learners(): void
    {
        $this->actingAs(User::factory()->create())->get(route('notebook.index'))
            ->assertOk()
            ->assertDontSee(route('admin.index'));
    }

    public function test_the_users_tab_lists_each_learners_ai_usage(): void
    {
        $learner = User::factory()->create(['name' => 'Sara']);
        AiUsageLog::factory()->for($learner)->count(2)->create();
        AiUsageLog::factory()->for($learner)->ownKey()->create();

        Livewire::actingAs($this->admin())->test('admin.index')
            ->set('tab', 'users')
            ->assertSee('Sara')
            ->assertSee('2 app · 1 own');
    }

    public function test_an_admin_message_reaches_the_learner(): void
    {
        Notification::fake();
        $learner = User::factory()->create();

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner])
            ->set('messageBody', 'Great week — keep going!')
            ->call('sendMessage')
            ->assertHasNoErrors()
            ->assertSet('messageBody', '');

        Notification::assertSentTo($learner, AdminMessage::class, fn (AdminMessage $message) => $message->toArray($learner)['title'] === 'Great week — keep going!');
    }

    public function test_an_empty_message_is_not_sent(): void
    {
        Notification::fake();
        $learner = User::factory()->create();

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner])
            ->call('sendMessage')
            ->assertHasErrors(['messageBody' => 'required']);

        Notification::assertNothingSent();
    }

    public function test_an_admin_message_is_shown_escaped_in_the_bell(): void
    {
        $learner = User::factory()->create();
        $learner->notify(new AdminMessage('<script>alert(1)</script>'));

        Livewire::actingAs($learner)->test('notifications.bell')
            ->assertSee('&lt;script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);
    }

    public function test_an_admin_can_limit_turn_off_and_free_a_learners_ai(): void
    {
        $learner = User::factory()->create();
        $page = Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner]);

        $page->set('aiLimit', '20')->call('saveAiLimit');
        $this->assertSame(20, $learner->fresh()->ai_daily_limit);

        $page->call('setAiLimit', 0);
        $this->assertSame(0, $learner->fresh()->ai_daily_limit);

        $page->call('setAiLimit', null);
        $this->assertNull($learner->fresh()->ai_daily_limit);
    }

    public function test_a_negative_ai_limit_is_refused(): void
    {
        $learner = User::factory()->create();

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner])
            ->set('aiLimit', '-1')
            ->call('saveAiLimit')
            ->assertHasErrors(['aiLimit' => 'min']);

        $this->assertNull($learner->fresh()->ai_daily_limit);
    }

    public function test_an_admin_can_make_a_learner_an_admin_and_take_it_back(): void
    {
        $learner = User::factory()->create();
        $page = Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner]);

        $page->call('toggleAdmin');
        $this->assertTrue($learner->fresh()->is_admin);

        $page->call('toggleAdmin');
        $this->assertFalse($learner->fresh()->is_admin);
    }

    public function test_an_admin_cannot_remove_their_own_admin_access(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)->test('admin.user', ['user' => $admin])
            ->call('toggleAdmin')
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_admin);
    }

    private function makeMission(string $code): Mission
    {
        return Mission::create([
            'code' => $code,
            'title' => 'Mission '.$code,
            'module' => 'Me',
            'outcome' => 'I can talk about it.',
            'phases' => [['phase' => 'foundation', 'label' => 'Day 1', 'steps' => [['key' => 'mission_brief']]]],
        ]);
    }

    public function test_an_admin_can_remove_a_learners_mission_with_its_answers_and_recordings(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('recordings/answer.webm', 'audio');
        Storage::disk('public')->put('recordings/draft.webm', 'audio');
        $learner = User::factory()->create();
        $kept = MissionRun::findOrStart($learner, $this->makeMission('M01'));
        $removed = MissionRun::findOrStart($learner, $this->makeMission('M10'));
        Evidence::create(['mission_run_id' => $removed->id, 'phase' => 'mission_brief', 'type' => Evidence::TYPE_AUDIO, 'content_ref' => '/storage/recordings/answer.webm']);
        StepDraft::factory()->for($removed, 'missionRun')->create(['paths' => ['recordings/draft.webm']]);

        Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner])
            ->call('discardMissionRun', $removed->id)
            ->assertSee('M10 removed.')
            ->assertSee('Mission M01');

        $this->assertModelMissing($removed);
        $this->assertModelExists($kept);
        $this->assertDatabaseMissing('evidences', ['mission_run_id' => $removed->id]);
        Storage::disk('public')->assertMissing(['recordings/answer.webm', 'recordings/draft.webm']);
    }

    public function test_an_admin_cannot_remove_another_learners_mission_from_this_learners_page(): void
    {
        $learner = User::factory()->create();
        $otherRun = MissionRun::findOrStart(User::factory()->create(), $this->makeMission('M01'));

        $page = Livewire::actingAs($this->admin())->test('admin.user', ['user' => $learner]);

        $this->assertThrows(fn () => $page->call('discardMissionRun', $otherRun->id), ModelNotFoundException::class);

        $this->assertModelExists($otherRun);
    }

    public function test_the_learner_page_shows_their_picture(): void
    {
        $learner = User::factory()->create(['name' => 'Sara', 'ai_daily_limit' => 5]);
        AiUsageLog::factory()->for($learner)->count(3)->create();

        $this->actingAs($this->admin())->get(route('admin.users.show', $learner))
            ->assertOk()
            ->assertSee('Sara')
            ->assertSee('Used today: 3.')
            ->assertSee("The program hasn't started yet.", escape: false);
    }
}
