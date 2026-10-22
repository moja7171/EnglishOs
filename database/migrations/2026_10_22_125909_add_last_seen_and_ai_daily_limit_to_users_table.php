<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // last_seen_at: when the learner last opened the app (see
        // TouchLastSeen). ai_daily_limit: the admin's cap on AI requests a
        // day on the app's key — null is no cap, 0 is no AI at all.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('ai_daily_limit')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'ai_daily_limit']);
        });
    }
};
