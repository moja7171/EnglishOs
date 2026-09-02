<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_log_items', function (Blueprint $table) {
            // A short AI-assigned slug (e.g. "third-person-s",
            // "article-usage") — freeform, not an enum, since the set of
            // possible grammar/vocabulary error categories isn't fixed.
            // Lets User::recurringErrorCategories() spot the SAME
            // underlying pattern across different missions even though
            // the exact sentences are never identical. Nullable: older
            // rows (and any AI response that skips it) just don't
            // participate in recurrence detection.
            $table->string('category')->nullable()->after('correction');
        });
    }

    public function down(): void
    {
        Schema::table('error_log_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
