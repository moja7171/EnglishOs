<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The finish date the learner was last told (see FinishLine), so a
        // new alert goes out only once it has moved 3 days or more.
        Schema::table('users', function (Blueprint $table) {
            $table->date('finish_line_told_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('finish_line_told_on');
        });
    }
};
