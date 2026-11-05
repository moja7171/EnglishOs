<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The game that is part 4 of a Pair Practice round; null for a game
        // from the Games page.
        Schema::table('pair_games', function (Blueprint $table) {
            $table->foreignId('pair_practice_session_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pair_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pair_practice_session_id');
        });
    }
};
