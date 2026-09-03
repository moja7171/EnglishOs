<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The grammar-point counterpart to error_pattern_reviews — same SM-2
     * shape (see App\Models\Concerns\HasSpacedRepetition), but enrolled
     * unconditionally the first time a mission's Grammar in Context step
     * is completed (see User::syncGrammarPoint()), not gated behind a
     * recurrence threshold — each mission only ever teaches its grammar
     * focus once, so there's no "has this recurred" signal to wait for.
     */
    public function up(): void
    {
        Schema::create('grammar_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('source_mission_run_id')->nullable()->constrained('mission_runs')->nullOnDelete();
            $table->string('mission_code');
            // Matches the mission content's own 'focus' string, e.g.
            // "Present Simple + Adverbs of Frequency" — one row per
            // learner per focus; re-teaching the same focus in a later
            // mission just refreshes it (updateOrCreate), same as
            // error_pattern_reviews' category.
            $table->string('focus');
            // Captured verbatim at enrollment time — the learner's own
            // real completed sentence and a short rule reminder — so a
            // later review never needs to re-fetch the original mission's
            // content (which could change) to render the prompt.
            $table->text('example_sentence');
            $table->text('rule_reminder');
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'focus']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grammar_points');
    }
};
