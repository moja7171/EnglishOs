<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Same shape as listening_logs: one row per learner per calendar day,
        // so ticking "I practiced" twice on the same day is the same fact.
        // mission_code/day_number only record which program day's voice
        // practice it was; the streak only ever reads practiced_on.
        Schema::create('pi_practice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->date('practiced_on');
            $table->string('mission_code', 3);
            $table->unsignedTinyInteger('day_number');
            $table->timestamps();

            $table->unique(['learner_id', 'practiced_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pi_practice_logs');
    }
};
