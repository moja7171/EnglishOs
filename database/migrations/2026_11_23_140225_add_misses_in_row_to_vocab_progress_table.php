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
        Schema::table('vocab_progress', function (Blueprint $table) {
            // Wrong answers in a row: at 4 the sentence goes on the course's "tricky" list; a right answer clears it.
            $table->unsignedSmallInteger('misses_in_row')->default(0)->after('lapses');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocab_progress', function (Blueprint $table) {
            $table->dropColumn('misses_in_row');
        });
    }
};
