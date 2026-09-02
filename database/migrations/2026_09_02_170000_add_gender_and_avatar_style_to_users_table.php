<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Optional by design ('unspecified' is a real, first-class
            // choice, not a missing value) — only used to pick a sensible
            // starting illustrated avatar style; never shown to anyone
            // else and never required. See User::defaultAvatarStyleForGender().
            $table->string('gender')->default('unspecified');
            // One of User::avatarStyleOptions()'s keys. 'initial' (the
            // plain color+letter avatar) is the default for everyone,
            // including 'unspecified' gender — a gendered illustrated
            // avatar is only ever a suggested starting point, picked
            // automatically the first time a learner sets a real gender
            // (see Profile::updateBasicInfo()), never forced.
            $table->string('avatar_style')->default('initial');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gender', 'avatar_style']);
        });
    }
};
