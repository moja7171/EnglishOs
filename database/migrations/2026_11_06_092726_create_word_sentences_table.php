<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sentences for a word in the learner's notebook: ones the AI made
     * for it ("ai") and ones the learner wrote themselves ("own").
     */
    public function up(): void
    {
        Schema::create('word_sentences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocabulary_word_id')->constrained()->cascadeOnDelete();
            $table->string('sentence', 300);
            $table->string('source', 10);
            $table->string('better', 300)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['vocabulary_word_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('word_sentences');
    }
};
