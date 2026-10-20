<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Listen Again no longer locks Continue behind hearing the audio, so
     * there is nothing left to remember per day.
     */
    public function up(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->dropColumn('listened_phases');
        });
    }

    public function down(): void
    {
        Schema::table('mission_runs', function (Blueprint $table) {
            $table->json('listened_phases')->nullable();
        });
    }
};
