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
        Schema::create('vocab_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocab_text_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            // The book's bold word or phrase, where it sits in the text body, and the sentence it is in.
            $table->string('phrase');
            $table->unsignedSmallInteger('start');
            $table->unsignedSmallInteger('end');
            $table->unsignedSmallInteger('sentence_index');
            $table->string('ipa')->nullable();
            // The book's own English explanation under the text.
            $table->text('gloss')->nullable();
            $table->string('phrase_fa')->nullable();
            // Where the mp3 of the section says this phrase, in seconds.
            $table->decimal('audio_start', 7, 2)->nullable();
            $table->decimal('audio_end', 7, 2)->nullable();
            $table->timestamps();

            $table->index(['vocab_text_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vocab_items');
    }
};
