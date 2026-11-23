<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The course's own review cards are gone: an added word is now a VocabularyWord reviewed in the Review tab.
        Schema::dropIfExists('vocab_progress');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Not restored: it held no real data.
    }
};
