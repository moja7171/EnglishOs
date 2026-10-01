<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New learners now default to Pre-Intermediate (A2+) instead of B1 —
     * see User::levelOptions(). Registration/profile already send this
     * value explicitly, but the column default matters for any row
     * inserted without it (factories, direct inserts).
     *
     * Uses the schema builder instead of raw MySQL ALTER COLUMN syntax so
     * this also runs on sqlite (local/test environments).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cefr_level')->default('A2+')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('cefr_level')->default('B1')->change();
        });
    }
};
