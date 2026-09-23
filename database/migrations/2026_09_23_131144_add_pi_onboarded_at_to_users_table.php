<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Set once, on /pi-setup, after the learner has created their 3
            // persistent Pi chats (teacher/partner/pronunciation coach) —
            // see App\Services\PiPrompts. Null gates the home route to
            // /pi-setup once, before their first mission.
            $table->timestamp('pi_onboarded_at')->nullable()->after('program_started_at');
        });

        // Learners already mid-program never saw /pi-setup and never will
        // be sent there retroactively — grandfather them in immediately so
        // the new gate only ever catches someone truly before their first
        // mission.
        DB::table('users')->whereNotNull('program_started_at')->update([
            'pi_onboarded_at' => DB::raw('program_started_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pi_onboarded_at');
        });
    }
};
