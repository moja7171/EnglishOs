<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw call counts only — no cost estimation (that needs a pricing
     * table that goes stale and adds speculative complexity). A real
     * 2026-09-03 end-to-end walkthrough of M01 alone made ~40 real AI
     * calls; this gives actual numbers to reason about scaling to 24
     * missions with, queryable directly, no admin surface built for it.
     */
    public function up(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->unsignedInteger('gemini_calls')->default(0)->after('struggle_signal_count');
            $table->unsignedInteger('groq_calls')->default(0)->after('gemini_calls');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->dropColumn(['gemini_calls', 'groq_calls']);
        });
    }
};
