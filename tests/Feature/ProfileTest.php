<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_form_is_pre_filled_with_the_learners_current_info(): void
    {
        $me = User::factory()->create(['name' => 'Priya', 'cefr_level' => 'B2', 'target_band' => '7.0']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->assertSet('name', 'Priya')
            ->assertSet('cefr_level', 'B2')
            ->assertSet('target_band', '7.0');
    }

    public function test_updating_basic_info_saves_name_level_and_target_band(): void
    {
        $me = User::factory()->create(['name' => 'Old Name', 'cefr_level' => 'A1']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('name', 'New Name')
            ->set('cefr_level', 'C1')
            ->set('target_band', '8.0')
            ->call('updateBasicInfo')
            ->assertSet('basicInfoSaved', true);

        $me->refresh();
        $this->assertSame('New Name', $me->name);
        $this->assertSame('C1', $me->cefr_level);
        $this->assertSame('8.0', $me->target_band);
    }

    public function test_a_blank_name_is_rejected(): void
    {
        $me = User::factory()->create(['name' => 'Keep Me']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('name', '')
            ->call('updateBasicInfo')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame('Keep Me', $me->fresh()->name);
    }

    public function test_selecting_a_color_updates_the_avatar_color(): void
    {
        $me = User::factory()->create(['avatar_color' => 'accent']);

        $this->actingAs($me);

        Livewire::test('profile')->call('selectColor', 'rose');

        $this->assertSame('rose', $me->fresh()->avatar_color);
    }

    public function test_an_unrecognized_color_key_is_silently_ignored(): void
    {
        $me = User::factory()->create(['avatar_color' => 'accent']);

        $this->actingAs($me);

        Livewire::test('profile')->call('selectColor', 'not-a-real-color');

        $this->assertSame('accent', $me->fresh()->avatar_color);
    }

    public function test_picking_a_color_clears_any_uploaded_photo(): void
    {
        Storage::fake('public');
        $me = User::factory()->create(['avatar_path' => 'avatars/existing.jpg']);
        Storage::disk('public')->put('avatars/existing.jpg', 'fake-image-bytes');

        $this->actingAs($me);

        Livewire::test('profile')->call('selectColor', 'sky');

        $this->assertNull($me->fresh()->avatar_path);
        Storage::disk('public')->assertMissing('avatars/existing.jpg');
    }

    public function test_uploading_a_photo_processes_and_stores_it_on_the_public_disk(): void
    {
        Storage::fake('public');
        $me = User::factory()->create();

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('newAvatar', UploadedFile::fake()->image('me.jpg', 800, 600))
            ->call('saveAvatar');

        $me->refresh();
        $this->assertSame('avatars/'.$me->id.'.jpg', $me->avatar_path);
        Storage::disk('public')->assertExists($me->avatar_path);
    }

    public function test_an_oversized_photo_is_rejected(): void
    {
        Storage::fake('public');
        $me = User::factory()->create();

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('newAvatar', UploadedFile::fake()->image('huge.jpg', 3000, 3000)->size(6000))
            ->call('saveAvatar')
            ->assertHasErrors(['newAvatar']);

        $this->assertNull($me->fresh()->avatar_path);
    }

    public function test_removing_the_photo_deletes_it_and_reverts_to_the_color_avatar(): void
    {
        Storage::fake('public');
        $me = User::factory()->create();
        Storage::disk('public')->put('avatars/'.$me->id.'.jpg', 'fake-image-bytes');
        $me->update(['avatar_path' => 'avatars/'.$me->id.'.jpg']);

        $this->actingAs($me);

        Livewire::test('profile')->call('removeAvatar');

        $this->assertNull($me->fresh()->avatar_path);
        Storage::disk('public')->assertMissing('avatars/'.$me->id.'.jpg');
    }

    public function test_toggling_discoverability_flips_it(): void
    {
        $me = User::factory()->create(['discoverable' => true]);

        $this->actingAs($me);

        Livewire::test('profile')->call('toggleDiscoverable');
        $this->assertFalse($me->fresh()->discoverable);

        Livewire::test('profile')->call('toggleDiscoverable');
        $this->assertTrue($me->fresh()->discoverable);
    }

    public function test_a_learner_who_turns_off_discoverability_stops_appearing_in_search(): void
    {
        $me = User::factory()->create();
        $bob = User::factory()->create(['name' => 'Bob Hidden']);

        $this->actingAs($bob);
        Livewire::test('profile')->call('toggleDiscoverable');

        $this->actingAs($me);
        Livewire::test('friends.index')
            ->set('search', 'Bob')
            ->assertDontSee('Bob Hidden');
    }

    public function test_updating_the_password_with_the_correct_current_password_succeeds(): void
    {
        $me = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('currentPassword', 'old-password-123')
            ->set('newPassword', 'new-password-456')
            ->set('newPassword_confirmation', 'new-password-456')
            ->call('updatePassword')
            ->assertSet('passwordSaved', true)
            ->assertSet('currentPassword', '')
            ->assertSet('newPassword', '');

        $this->assertTrue(Hash::check('new-password-456', $me->fresh()->password));
    }

    public function test_the_wrong_current_password_is_rejected(): void
    {
        $me = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('currentPassword', 'totally-wrong')
            ->set('newPassword', 'new-password-456')
            ->set('newPassword_confirmation', 'new-password-456')
            ->call('updatePassword')
            ->assertHasErrors(['currentPassword']);

        $this->assertTrue(Hash::check('old-password-123', $me->fresh()->password));
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $me = User::factory()->create(['password' => 'old-password-123']);

        $this->actingAs($me);

        Livewire::test('profile')
            ->set('currentPassword', 'old-password-123')
            ->set('newPassword', 'new-password-456')
            ->set('newPassword_confirmation', 'does-not-match')
            ->call('updatePassword')
            ->assertHasErrors(['newPassword']);

        $this->assertTrue(Hash::check('old-password-123', $me->fresh()->password));
    }
}
