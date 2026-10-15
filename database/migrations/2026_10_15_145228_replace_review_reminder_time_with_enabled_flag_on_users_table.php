<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The review reminder no longer has a learner-chosen time: it goes
            // out at a fixed evening hour, and only to someone who hasn't
            // reviewed yet. All that is left to choose is on or off.
            $table->boolean('review_reminder_enabled')->default(true);
        });

        // A learner who had switched it off (null time) stays switched off.
        DB::table('users')->whereNull('review_reminder_time')->update(['review_reminder_enabled' => false]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('review_reminder_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('review_reminder_time', 5)->nullable()->default('19:00');
        });

        DB::table('users')->where('review_reminder_enabled', false)->update(['review_reminder_time' => null]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('review_reminder_enabled');
        });
    }
};
