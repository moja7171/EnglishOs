<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile › Reminders › Friends: news about friends' days ("studying
     * now", "your turn", "hasn't studied yet") — on unless switched off.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('friend_alerts')->default(true)->after('discoverable');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('friend_alerts');
        });
    }
};
