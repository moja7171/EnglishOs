<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When a line was last practised (notebook practice or Daily
        // Review). A line saved by feedback after it — or never practised —
        // is new and lights the dot on the Notebook tab (see
        // NotebookEntry::scopeUnpractised()). Lines saved before this
        // count as seen, so no learner starts with a dot they can't clear.
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->timestamp('last_practised_at')->nullable()->after('practice_level');
        });

        DB::table('notebook_entries')->update(['last_practised_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->dropColumn('last_practised_at');
        });
    }
};
