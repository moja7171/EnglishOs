<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One learner's voice during one game or Pair Practice round: the
        // pieces their phone recorded (only their own speaking time), each
        // with its transcript, and the sentence-by-sentence feedback on them
        // once the game is over. The audio files live on the private disk
        // and are pruned with the row after a week.
        Schema::create('voice_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->morphs('subject');
            $table->json('clips');
            $table->string('status', 16);
            $table->json('feedback')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['learner_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_feedback');
    }
};
