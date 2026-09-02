<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            // A live, run-scoped counter of real ("major") AI check misses
            // so far — read by aiToneGuidance() to adapt tone as the
            // mission actually unfolds, not just once from Day 1's
            // self-report. See TracksCheckAttempts::trackCheckAttempt().
            $table->unsignedInteger('struggle_signal_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->dropColumn('struggle_signal_count');
        });
    }
};
