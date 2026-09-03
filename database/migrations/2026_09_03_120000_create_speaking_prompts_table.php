<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speaking_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            // Where this prompt was first picked, kept only for reference —
            // nullable for the same reason as VocabularyWord's equivalent
            // column: the review schedule outlives any one mission run.
            $table->foreignId('source_mission_run_id')->nullable()->constrained('mission_runs')->nullOnDelete();
            // Captured once at creation, e.g. "M01" — shown next to the
            // prompt so the learner has some sense of when they first met
            // this question, independent of the run itself still existing.
            $table->string('mission_code')->nullable();
            $table->text('prompt');
            // The learner's most recent recorded answer — deliberately just
            // the latest, not a full history per attempt (keeps this simple
            // and consistent with how every other mission step's voice
            // input already works: one current recording, re-recordable).
            $table->string('last_recording_url')->nullable();
            // SM-2 (SuperMemo, 1987) scheduling state — same shape as
            // VocabularyWord's, see HasSpacedRepetition.
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            // One tracked row per learner per exact prompt text — picking
            // the same question again from a later mission just means it
            // was already on the list, not a second, separately-scheduled
            // card.
            $table->unique(['learner_id', 'prompt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speaking_prompts');
    }
};
