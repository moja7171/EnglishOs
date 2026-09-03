<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New learners now default to Pre-Intermediate (A2+) instead of B1 —
     * see User::levelOptions(). Registration/profile already send this
     * value explicitly, but the column default matters for any row
     * inserted without it (factories, direct inserts).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN cefr_level SET DEFAULT 'A2+'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users ALTER COLUMN cefr_level SET DEFAULT 'B1'");
    }
};
