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
        Schema::table('persian_glosses', function (Blueprint $table) {
            // 'wiktionary' for the offline dictionary built from Wiktionary, 'gemini' for
            // words it lacks, filled in by the dictionary:fill-persian command.
            $table->string('source', 20)->default('wiktionary')->after('words');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('persian_glosses', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
