<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The last Pi chat a learner pasted on the /pi page and the feedback
        // it got, one per mission day, so coming back to that day shows it.
        Schema::create('pi_chat_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('mission_code');
            $table->unsignedTinyInteger('day_number');
            $table->text('chat');
            $table->json('feedback');
            $table->timestamps();

            $table->unique(['learner_id', 'mission_code', 'day_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pi_chat_feedback');
    }
};
