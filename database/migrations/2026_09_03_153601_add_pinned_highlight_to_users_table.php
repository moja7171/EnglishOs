<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A short, entirely self-authored blurb a learner can choose to show
     * on their own Friends Board card (see friends/⚡board.blade.php) —
     * opt-in, empty by default, never derived from AI-graded Evidence.
     * This is the one piece of "content" a friend can ever see on the
     * board; every other stat there is a count/percentage/date, never
     * the learner's actual answers.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pinned_highlight', 80)->nullable()->after('avatar_color');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pinned_highlight');
        });
    }
};
