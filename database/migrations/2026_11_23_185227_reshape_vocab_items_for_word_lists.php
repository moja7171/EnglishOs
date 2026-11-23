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
        Schema::table('vocab_items', function (Blueprint $table) {
            // A new word of a lesson: where it sits in the section's text (when it does) and the book sentence it comes from.
            $table->unsignedSmallInteger('start')->nullable()->change();
            $table->unsignedSmallInteger('end')->nullable()->change();
            $table->unsignedSmallInteger('sentence_index')->nullable()->change();
            $table->text('sentence')->nullable()->after('gloss');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vocab_items', function (Blueprint $table) {
            $table->dropColumn('sentence');
        });
    }
};
