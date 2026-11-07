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
        Schema::table('users', function (Blueprint $table) {
            // Ask & Answer: the level (1-3) of the questions and of the answers the learner picked last time.
            $table->unsignedTinyInteger('ask_question_level')->default(1);
            $table->unsignedTinyInteger('ask_answer_level')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ask_question_level', 'ask_answer_level']);
        });
    }
};
