<?php

use App\Models\VocabularyWord;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            // What the review card shows once the learner reveals a word —
            // all nullable: words saved from older missions, or from steps
            // that never authored them, simply show less.
            $table->string('pos')->nullable()->after('meaning');
            $table->text('example')->nullable()->after('pos');
            $table->text('user_sentence')->nullable()->after('example');
        });

        VocabularyWord::query()
            ->whereNotNull('source_mission_run_id')
            ->each(fn (VocabularyWord $word) => $word->fillMissingDetailsFromSource());
    }

    public function down(): void
    {
        Schema::table('vocabulary_words', function (Blueprint $table) {
            $table->dropColumn(['pos', 'example', 'user_sentence']);
        });
    }
};
