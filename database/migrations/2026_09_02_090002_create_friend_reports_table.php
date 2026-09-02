<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No in-app moderation queue exists yet — reporting just leaves a
        // durable paper trail (checked manually for now) while the
        // reporting user separately blocks the other person for immediate
        // relief. Keeps the reported message's exact text at the time of
        // the report, since the sender could edit/delete it later.
        Schema::create('friend_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->text('message_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_reports');
    }
};
