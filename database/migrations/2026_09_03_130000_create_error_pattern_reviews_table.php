<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_pattern_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            // Matches ErrorLogItem::category — one row per learner per
            // recurring category (see User::recurringErrorCategories()),
            // not one row per mistake instance; a category only starts
            // being tracked here once it has recurred across 2+ missions.
            $table->string('category');
            // Refreshed to the MOST RECENT real example every time this
            // category recurs — grounds the spaced-repetition prompt in a
            // concrete, current wrong/correct pair, not a stale one.
            $table->text('last_error');
            $table->text('last_correction');
            // SM-2 (SuperMemo, 1987) scheduling state — same shape as
            // VocabularyWord's, see HasSpacedRepetition. A fresh real-world
            // recurrence of this category is itself treated as a failed
            // review (quality 1), rescheduling it the same way a failed
            // spaced-repetition check would.
            $table->decimal('ease_factor', 4, 2)->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at');
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_pattern_reviews');
    }
};
