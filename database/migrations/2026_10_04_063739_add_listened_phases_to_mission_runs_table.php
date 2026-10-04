<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            // Listen Again days (daily_listen_2/3/4) whose audio the learner
            // has already heard. Deliberately not Evidence — any Evidence row
            // for a step marks it finished (see MissionRun::currentStepKey()),
            // and "I listened" alone isn't the whole step. Kept here so
            // leaving the page and coming back doesn't lock Continue again.
            $table->json('listened_phases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->dropColumn('listened_phases');
        });
    }
};
