<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phrase_forms', function (Blueprint $table) {
            $table->id();
            // The English phrasal verbs and fixed phrases the dictionary
            // knows (Wiktionary, built by scripts/persian-glosses/build.py
            // and loaded by PhraseFormSeeder): one row per way the phrase
            // can be written in a text — "gave up" -> "give up", and the
            // phrase itself.
            $table->string('form', 60);
            $table->string('phrase', 60);

            $table->unique(['form', 'phrase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phrase_forms');
    }
};
