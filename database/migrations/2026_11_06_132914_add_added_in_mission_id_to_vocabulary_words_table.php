<?php

use App\Services\WordMissions;
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
        Schema::table('vocabulary_words', function (Blueprint $table) {
            // The mission a learner was on when they added the word themselves, so
            // the Words tab can keep it in that mission's card (null for a word the
            // lessons gave them: those have source_mission_run_id).
            $table->foreignId('added_in_mission_id')->nullable()->after('source_mission_run_id')->constrained('missions')->nullOnDelete();
        });

        app(WordMissions::class)->backfill();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_in_mission_id');
        });
    }
};
