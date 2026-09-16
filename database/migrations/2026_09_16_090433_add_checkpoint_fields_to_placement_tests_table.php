<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            // 'initial' (the registration-time test) or 'checkpoint' (a
            // consolidation-day "your voice, N months in" redo — see
            // App\Services\ProgramPlanner::CHECKPOINT_MISSIONS). Same
            // table, same scoring, because a checkpoint retakes exactly
            // the placement test's speaking part.
            $table->string('kind')->default('initial')->after('learner_id');

            // Which mission's consolidation day this checkpoint belongs
            // to ("M06"), null for the 'initial' kind.
            $table->string('checkpoint_mission_code')->nullable()->after('kind');

            // The recording itself, stored the same way every other
            // spoken Evidence is (see Activation) — needed so a later
            // checkpoint can play the OLD recording back next to the new
            // one. Nullable: the very first placement_tests rows
            // predate this column and never stored the file.
            $table->string('audio_url')->nullable()->after('transcript');
        });
    }

    public function down(): void
    {
        Schema::table('placement_tests', function (Blueprint $table) {
            $table->dropColumn(['kind', 'checkpoint_mission_code', 'audio_url']);
        });
    }
};
