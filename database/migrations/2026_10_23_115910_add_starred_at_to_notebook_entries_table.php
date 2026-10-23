<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A "say it like this" phrase swap only goes to Daily Review once
        // the learner stars it on the notebook page (see
        // NotebookEntry::scopeInReview()); mistakes and techniques always do.
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->timestamp('starred_at')->nullable()->after('learned_at');
        });
    }

    public function down(): void
    {
        Schema::table('notebook_entries', function (Blueprint $table) {
            $table->dropColumn('starred_at');
        });
    }
};
