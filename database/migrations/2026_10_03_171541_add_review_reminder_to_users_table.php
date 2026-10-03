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
            // Local "HH:MM" the learner wants the daily review reminder at;
            // null = reminders off. Defaults on at 19:00 — it only ever
            // reaches someone who has also turned on phone alerts.
            $table->string('review_reminder_time', 5)->nullable()->default('19:00');
            // IANA zone the reminder time is read in, captured from the
            // browser; null falls back to the app's Tehran default.
            $table->string('timezone', 64)->nullable();
            // Local date of the last reminder attempt — at most one a day.
            $table->date('last_review_reminder_on')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['review_reminder_time', 'timezone', 'last_review_reminder_on']);
        });
    }
};
