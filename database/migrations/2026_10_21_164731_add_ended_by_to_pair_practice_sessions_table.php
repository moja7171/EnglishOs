<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who tapped End (or cancelled the invite), so the other phone can
        // say "Sara ended the round". Null when the round ran to its end.
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->foreignId('ended_by_id')->nullable()->after('joined_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pair_practice_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ended_by_id');
        });
    }
};
