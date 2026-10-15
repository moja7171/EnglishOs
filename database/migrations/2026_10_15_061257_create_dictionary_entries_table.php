<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dictionary_entries', function (Blueprint $table) {
            $table->id();
            // Lower-cased and trimmed (see App\Services\WordLookup::normalize())
            // — one shared row per word for every learner, so the outside
            // dictionary and translation services are asked about a word
            // once, ever.
            $table->string('word')->unique();
            $table->string('phonetic')->nullable();
            $table->string('audio_url', 500)->nullable();
            // [{pos, definitions: [{definition, example}]}]. An empty list
            // means the dictionary was asked and does not know the word —
            // kept so a made-up or misspelled word isn't asked about again.
            $table->json('meanings');
            // Null when the translation service had nothing or was down —
            // WordLookup retries it on a later tap rather than caching the gap.
            $table->string('translation_fa')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionary_entries');
    }
};
