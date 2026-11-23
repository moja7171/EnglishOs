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
        Schema::create('vocab_units', function (Blueprint $table) {
            $table->id();
            // The book's own unit number (5-94): every unit is open from the start, there is no order.
            $table->unsignedSmallInteger('number')->unique();
            $table->string('title');
            // One of the book's 16 sections ("Daily life"), numbered in book order.
            $table->unsignedTinyInteger('book_section');
            $table->string('book_section_name');
            // The PDF page the unit starts on.
            $table->unsignedSmallInteger('page');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vocab_units');
    }
};
