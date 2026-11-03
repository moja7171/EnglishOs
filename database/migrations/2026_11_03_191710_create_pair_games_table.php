<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One game from the Games page: two friends, each on their own
        // phone, 5 or 10 minutes of one game. The pair is stored low/high id
        // like pair_practice_sessions. The cards are drawn once (deck) and
        // every turn with what happened to its cards is appended to turns,
        // so a reload resumes the game. score counts half points (a word
        // guessed after "Show first letter" is worth ½).
        Schema::create('pair_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('started_by_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ended_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('game', 24);
            $table->unsignedTinyInteger('minutes');
            $table->json('rules');
            $table->json('deck');
            $table->json('turns');
            $table->string('stage', 16);
            $table->unsignedSmallInteger('score')->default(0);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_a_id', 'user_b_id', 'game']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pair_games');
    }
};
