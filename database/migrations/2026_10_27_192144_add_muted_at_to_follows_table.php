<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mute in a chat's ⋯ menu: set on the muter's own follow row
     * (follower = the one who muted), so that friend's messages, nudges
     * and activity alerts stop notifying; the chat and unread count stay.
     */
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropColumn('muted_at');
        });
    }
};
