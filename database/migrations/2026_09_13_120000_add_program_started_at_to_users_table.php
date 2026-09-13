<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Day 1 of the learner's 120-day program (see
            // App\Services\ProgramPlanner) — set the moment their first
            // MissionRun is created, never editable. Null until then.
            $table->timestamp('program_started_at')->nullable()->after('weekly_goal_days');
        });

        // Learners who already started before this existed: their first
        // mission run IS their day 1, so the program counts from there
        // rather than restarting everyone at day 1 today.
        $firstRuns = DB::table('mission_runs')
            ->selectRaw('learner_id, MIN(started_at) as first_started_at')
            ->groupBy('learner_id')
            ->get();

        foreach ($firstRuns as $row) {
            DB::table('users')->where('id', $row->learner_id)->update(['program_started_at' => $row->first_started_at]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('program_started_at');
        });
    }
};
