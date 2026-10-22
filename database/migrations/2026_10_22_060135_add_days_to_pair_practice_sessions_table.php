<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The days the starter picked ("M01-2", …), so Another round can
        // draw from the same ones. Null for an automatic round (newest
        // shared day + review).
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->json('days')->nullable()->after('day_number');
        });
    }

    public function down(): void
    {
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->dropColumn('days');
        });
    }
};
