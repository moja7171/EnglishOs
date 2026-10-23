<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The first local day the streak ring's rules apply to this learner
        // (2 activities keep the day, freezes instead of the silent grace
        // day). Everyone already here starts tomorrow, so no streak breaks
        // overnight; a new learner starts on the day they sign up (null).
        Schema::table('users', function (Blueprint $table) {
            $table->date('streak_ring_since')->nullable()->after('celebrated_streak_milestone');
        });

        DB::table('users')->update(['streak_ring_since' => now('Asia/Tehran')->addDay()->toDateString()]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('streak_ring_since');
        });
    }
};
