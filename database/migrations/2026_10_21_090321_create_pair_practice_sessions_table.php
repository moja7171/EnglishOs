<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One in-person practice round of two friends on one phone. The pair
        // is stored low/high id (like partner_sessions) so both friends share
        // one history. The cards are drawn once at the start (deck) and every
        // answered card is appended to results, so a reload resumes the round.
        Schema::create('pair_practice_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('started_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('mission_code', 3);
            $table->unsignedTinyInteger('day_number');
            $table->json('deck');
            $table->json('results');
            $table->string('stage', 16);
            $table->timestamp('stage_ends_at')->nullable();
            $table->unsignedSmallInteger('score_a')->default(0);
            $table->unsignedSmallInteger('score_b')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_a_id', 'user_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pair_practice_sessions');
    }
};
