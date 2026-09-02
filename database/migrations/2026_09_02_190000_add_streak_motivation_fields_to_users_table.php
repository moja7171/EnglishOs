<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The highest streak milestone (7/30/100) already celebrated —
            // see User::streakMilestoneJustReached(). Without this, the
            // celebration (computed fresh from currentStreak() every
            // render, never stored) would replay on every single page view
            // for as long as the streak stays at or above that milestone.
            $table->unsignedInteger('celebrated_streak_milestone')->default(0);
            // Null means "no goal set" — a real, first-class state (opt-in,
            // never assumed), not a missing value. See
            // User::activeDaysThisWeek() for the progress this is measured
            // against.
            $table->unsignedTinyInteger('weekly_goal_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['celebrated_streak_milestone', 'weekly_goal_days']);
        });
    }
};
