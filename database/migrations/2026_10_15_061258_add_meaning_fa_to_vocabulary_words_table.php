<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            // The Persian meaning shown beside `meaning` on the review card —
            // nullable: words saved from a mission step have none, and a
            // dictionary lookup whose translation failed saves without one.
            $table->string('meaning_fa')->nullable()->after('meaning');
        });
    }

    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropColumn('meaning_fa');
        });
    }
};
