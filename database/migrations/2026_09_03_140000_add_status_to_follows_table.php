<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Following now requires the other side's consent — a follow() call
     * creates a 'pending' row instead of instantly counting as a real
     * follow; the target sees it as a request (with a notification badge
     * on the Friends icon) and can accept or reject it. Accepting creates
     * BOTH directions as 'accepted' at once — see User::acceptFollowRequest().
     * Existing rows default to 'accepted' so nothing already-established
     * (dev-seeded data, etc.) is silently revoked by this change.
     */
    public function up(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->string('status')->default('accepted')->after('followed_id');
        });
    }

    public function down(): void
    {
        Schema::table('follows', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
