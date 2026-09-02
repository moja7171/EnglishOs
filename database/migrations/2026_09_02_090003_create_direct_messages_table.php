<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No separate "conversation" entity — any two users can only ever
        // have one ongoing thread (strictly 1:1 by design, see
        // User::canMessageWith()), so a thread is just every message
        // between that pair, queried directly. A "nudge" (see the
        // 'nudge' type) is a lightweight preset encouragement, not a
        // full message — same table, rendered differently in the thread.
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('message'); // message | nudge
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_messages');
    }
};
