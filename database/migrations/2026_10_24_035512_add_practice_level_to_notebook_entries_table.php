<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which rung of the practice ladder a line is on: 1 tap the wrong
        // word, 2 pick the fix, 3 say it (see NotebookDrill). Up one per
        // right answer, down one per wrong one.
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->unsignedTinyInteger('practice_level')->default(1)->after('starred_at');
        });
    }

    public function down(): void
    {
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->dropColumn('practice_level');
        });
    }
};
