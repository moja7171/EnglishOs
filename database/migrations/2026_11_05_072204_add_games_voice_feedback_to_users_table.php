<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Feedback on my sentences" on the Games page: off unless the
        // learner turns it on. Pair Practice keeps game_voice_feedback.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('games_voice_feedback')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('games_voice_feedback');
        });
    }
};
