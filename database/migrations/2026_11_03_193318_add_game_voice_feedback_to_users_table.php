<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Feedback on my sentences" in games and Pair Practice: on unless
        // the learner turns it off; the choice is kept for next time.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('game_voice_feedback')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('game_voice_feedback');
        });
    }
};
