<?php

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
        Schema::create('vocab_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vocab_text_id')->constrained()->cascadeOnDelete();
            // One card is one sentence of the book; words sharing a sentence share the card.
            $table->unsignedSmallInteger('sentence_index');
            // The vocab_items the learner saved in this sentence.
            $table->json('saved_item_ids');
            // Review ladder (1 see, 2 gap, 3 order, 4 recall) and the misses that make the sentence "tricky".
            $table->unsignedTinyInteger('ladder_step')->default(1);
            $table->unsignedSmallInteger('lapses')->default(0);
            $table->float('ease_factor')->default(2.5);
            $table->unsignedInteger('interval_days')->default(0);
            $table->unsignedInteger('repetitions')->default(0);
            $table->timestamp('next_review_at')->nullable();
            $table->timestamp('last_reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'vocab_text_id', 'sentence_index']);
            $table->index(['user_id', 'next_review_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vocab_progress');
    }
};
