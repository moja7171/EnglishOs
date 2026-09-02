<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vocabulary_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            // Where this word was first picked, kept only for reference —
            // nullable because the review schedule below outlives any one
            // mission run and must never break if that run is ever pruned.
            $table->foreignId('source_mission_run_id')->nullable()->constrained('mission_runs')->nullOnDelete();
            $table->string('word');
            // Captured once at creation from the mission's own story_words
            // (see Mission::stepContent('vocabulary_builder')) — a stable
            // definition to flip the flashcard to, independent of whatever
            // mission content looks like by the time it's reviewed.
            $table->string('meaning')->nullable();
            // SM-2 (SuperMemo, 1987) scheduling state — see
            // VocabularyWord::review(). ease_factor never drops below 1.3;
            // interval_days is the current gap in days; repetitions is the
            // consecutive-success streak (reset to 0 on a failed review,
            // which is also the signal for "needs a written, AI-checked
            // review" rather than a quick self-assessment).
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            // One tracked row per learner per word — picking the same word
            // again in a later mission just means it was already known,
            // not a second, separately-scheduled card.
            $table->unique(['learner_id', 'word']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vocabulary_words');
    }
};
