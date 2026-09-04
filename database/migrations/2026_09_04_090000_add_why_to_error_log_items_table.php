<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_log_items', function (Blueprint $table) {
            // A short Persian sentence explaining the grammar/vocabulary
            // rule behind the mistake — shown alongside error/correction.
            // Nullable: older rows generated before this field existed
            // just don't show a "why" note.
            $table->text('why')->nullable()->after('correction');
        });
    }

    public function down(): void
    {
        Schema::table('error_log_items', function (Blueprint $table) {
            $table->dropColumn('why');
        });
    }
};
