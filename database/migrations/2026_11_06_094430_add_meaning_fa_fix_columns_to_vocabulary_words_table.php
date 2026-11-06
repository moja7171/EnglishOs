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
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->string('meaning_fa_previous')->nullable()->after('meaning_fa');
            $table->timestamp('meaning_fa_checked_at')->nullable()->after('meaning_fa_previous');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropColumn(['meaning_fa_previous', 'meaning_fa_checked_at']);
        });
    }
};
