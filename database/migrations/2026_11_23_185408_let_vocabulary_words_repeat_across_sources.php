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
            // The same expression may be a lesson word and a Vocab in Use word ("Add anyway").
            $table->dropUnique(['learner_id', 'word']);
            $table->unique(['learner_id', 'source', 'word']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropUnique(['learner_id', 'source', 'word']);
            $table->unique(['learner_id', 'word']);
        });
    }
};
