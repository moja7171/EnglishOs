<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Up to two local "HH:MM" study-time alerts a day; null = off
            // (the default — nothing arrives until the learner picks a time).
            $table->string('study_reminder_time_1', 5)->nullable();
            $table->string('study_reminder_time_2', 5)->nullable();
            // Local date each slot last fired — at most once a day per slot.
            $table->date('last_study_reminder_1_on')->nullable();
            $table->date('last_study_reminder_2_on')->nullable();
            // Local date of the last "your streak is about to break" alert.
            $table->date('last_streak_warning_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'study_reminder_time_1',
                'study_reminder_time_2',
                'last_study_reminder_1_on',
                'last_study_reminder_2_on',
                'last_streak_warning_on',
            ]);
        });
    }
};
