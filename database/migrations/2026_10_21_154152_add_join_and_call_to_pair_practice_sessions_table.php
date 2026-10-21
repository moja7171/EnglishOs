<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pair Practice is now played on two phones: a round waits until the
        // invited friend joins (joined_at), and the starter can share an
        // optional video-call link (Google Meet) for practising apart.
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->string('meet_link')->nullable()->after('day_number');
            $table->timestamp('joined_at')->nullable()->after('meet_link');
        });
    }

    public function down(): void
    {
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->dropColumn(['meet_link', 'joined_at']);
        });
    }
};
