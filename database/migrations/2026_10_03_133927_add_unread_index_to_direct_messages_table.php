<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The unread-conversation badge runs on every page render, always as
        // "this recipient's rows with no read_at".
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->index(['recipient_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('direct_messages', function (Blueprint $table) {
            $table->dropIndex(['recipient_id', 'read_at']);
        });
    }
};
