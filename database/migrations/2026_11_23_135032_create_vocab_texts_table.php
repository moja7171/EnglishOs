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
        Schema::create('vocab_texts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vocab_unit_id')->constrained()->cascadeOnDelete();
            // A to E: one short text of the unit, read aloud by one mp3 of the book's audio.
            $table->string('letter', 1);
            $table->string('title');
            // numbered, bracket or prose (how the book writes the glosses); a table or picture section has no sentences.
            $table->string('kind', 12);
            $table->text('body');
            // [{text, start, end, audio_start, audio_end, fa}] — sentence spans in `body`, and where the mp3 says them.
            $table->json('sentences');
            // The lines of a table the book prints instead of sentences.
            $table->json('rows')->nullable();
            $table->text('notes')->nullable();
            $table->string('audio_file')->nullable();
            $table->timestamps();

            $table->unique(['vocab_unit_id', 'letter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vocab_texts');
    }
};
