<?php

use App\Services\WordMissions;
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
        Schema::table('notebook_entries', function (Blueprint $table) {
            // The mission the line was last made in (a lesson's own, else the one the
            // learner was on), so the Mistakes tab can show and group by it.
            $table->foreignId('mission_id')->nullable()->after('learner_id')->constrained('missions')->nullOnDelete();
        });

        // Speaking techniques are no longer lines in the notebook (one Tip card at a time
        // shows the newest, see FeedbackNotebook): the ones kept before are put away.
        DB::table('notebook_entries')->where('kind', 'tip')->whereNotNull('label')->whereNull('learned_at')->update(['learned_at' => now()]);

        app(WordMissions::class)->backfillEntries();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mission_id');
        });
    }
};
