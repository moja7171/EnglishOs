<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persian_glosses', function (Blueprint $table) {
            $table->id();
            // The offline English -> Persian dictionary (Wiktionary, built by
            // scripts/persian-glosses/build.py and loaded by PersianGlossSeeder):
            // one row per lower-cased English word and part of speech.
            $table->string('word', 40);
            $table->string('pos', 20);
            // ["خواب", "رؤیا"] — the Persian equivalents, most common first.
            $table->json('words');
            $table->timestamps();

            $table->unique(['word', 'pos']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('persian_glosses');
    }
};
