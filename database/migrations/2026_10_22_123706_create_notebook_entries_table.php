<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The learner's two notebooks, filled automatically by every AI
        // feedback (see FeedbackNotebook): mistakes, and tips ("say it like
        // this" swaps and speaking techniques). One row per learner per
        // dedupe_key — the same mistake again only bumps times_seen.
        Schema::create('notebook_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            // 'mistake' or 'tip'.
            $table->string('kind', 16);
            $table->string('dedupe_key');
            // A mistake's rule ("present perfect with for"), or a tip's
            // technique key; null for a plain phrase swap.
            $table->string('label')->nullable();
            // The learner's own sentence, null for a technique.
            $table->text('original')->nullable();
            // The fixed/natural English, or a technique's example.
            $table->text('better');
            // Why, in Persian.
            $table->text('note')->nullable();
            $table->string('source', 32);
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('last_seen_at');
            // "I've learned this": off the review and the main list.
            $table->timestamp('learned_at')->nullable();
            // SM-2 scheduling state, see HasSpacedRepetition.
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'dedupe_key']);
            $table->index(['learner_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notebook_entries');
    }
};
