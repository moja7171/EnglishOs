<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The preset reason picked in the report form (see
        // FriendReport::CATEGORIES). Nullable: reports filed before this
        // existed only have the free-text `reason`.
        Schema::table('friend_reports', function (Blueprint $table) {
            $table->string('category')->nullable()->after('reported_id');
        });
    }

    public function down(): void
    {
        Schema::table('friend_reports', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
