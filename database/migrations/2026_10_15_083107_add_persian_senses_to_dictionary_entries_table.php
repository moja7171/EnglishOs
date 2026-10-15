<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dictionary_entries', function (Blueprint $table) {
            // [{pos, words}] — the Persian equivalents of the word for each
            // part of speech it can be, written by Gemini after the quick
            // first answer. Null until then (or if Gemini could not be
            // asked); translation_fa stays as the fallback.
            $table->json('persian_senses')->nullable()->after('translation_fa');
        });
    }

    public function down(): void
    {
        Schema::table('dictionary_entries', function (Blueprint $table) {
            $table->dropColumn('persian_senses');
        });
    }
};
