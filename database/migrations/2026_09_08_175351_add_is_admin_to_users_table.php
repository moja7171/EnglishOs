<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Deliberately not in User's #[Fillable] list — only settable
            // directly (seeder/tinker), never through registration or a
            // profile-update form. Bypasses Evidence Before Progress
            // gating everywhere MissionRun checks it — see
            // User::bypassesEvidenceGating().
            $table->boolean('is_admin')->default(false)->after('cefr_level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
