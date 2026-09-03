<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Originally one-directional and instant (Twitter-style, no
        // approval step) — superseded by the 'status' column added in
        // 2026_09_03_140000_add_status_to_follows_table.php, which makes
        // a follow a pending request the other side must accept. See
        // User::follow()/acceptFollowRequest() for the current behavior.
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
