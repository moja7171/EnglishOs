<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-directional by design — following someone is instant, no
        // approval step, matching "follow as many as you want" (Twitter-
        // style). A conversation additionally requires BOTH directions to
        // exist (see User::isMutualWith()) — that's what actually gates
        // messaging, not this table alone.
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('followed_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['follower_id', 'followed_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follows');
    }
};
