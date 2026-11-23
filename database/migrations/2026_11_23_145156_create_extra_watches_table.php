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
        Schema::create('extra_watches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extra_episode_id')->constrained()->cascadeOnDelete();
            // When the episode was really watched (about 80% played, not skipped to).
            $table->timestamp('watched_at');
            $table->timestamps();

            $table->unique(['user_id', 'extra_episode_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extra_watches');
    }
};
