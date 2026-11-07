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
        Schema::create('ask_answer_items', function (Blueprint $table) {
            $table->id();
            // "m11-d2-job": one situation of the Ask & Answer game, written for one mission day.
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('mission_number');
            $table->unsignedTinyInteger('day');
            // 'simple' = question then answer; 'followup' = a question about something the friend just said.
            $table->string('kind', 12);
            $table->string('situation', 60);
            // Follow-ups only: the line the friend "just said", in Persian, shown above the question.
            $table->string('context_fa')->nullable();
            // Levels 1-3 each: Persian prompt + optional extra, the English, other accepted sentences, known wrong versions.
            $table->json('questions');
            $table->json('answers');
            $table->timestamps();

            $table->index(['mission_number', 'day']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ask_answer_items');
    }
};
