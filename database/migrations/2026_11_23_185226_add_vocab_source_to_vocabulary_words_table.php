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
            // 'lesson' = a word from a mission's vocabulary step; 'vocab' = added from a Vocab in Use lesson.
            $table->string('source', 12)->default('lesson')->after('learner_id');
            $table->foreignId('vocab_item_id')->nullable()->after('source')->constrained('vocab_items')->nullOnDelete();
            $table->index(['learner_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'source']);
            $table->dropConstrainedForeignId('vocab_item_id');
            $table->dropColumn('source');
        });
    }
};
