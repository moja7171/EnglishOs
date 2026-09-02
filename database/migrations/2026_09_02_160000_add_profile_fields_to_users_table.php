<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A fixed palette key (see User::avatarColorPalette()), not a
            // raw hex value — keeps every avatar visually consistent with
            // the app's existing token-driven design system instead of
            // letting a learner pick an arbitrary, possibly illegible color.
            $table->string('avatar_color')->default('accent');
            // Relative path on the 'public' disk (e.g. "avatars/42.jpg") —
            // null until a learner uploads a real photo, in which case it
            // takes over from the color+initial avatar. See
            // Profile::processAvatarUpload().
            $table->string('avatar_path')->nullable();
            // Whether this learner can be found by name in Friends search
            // — does not affect an existing follow/conversation, only
            // whether NEW people can find them going forward.
            $table->boolean('discoverable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_color', 'avatar_path', 'discoverable']);
        });
    }
};
