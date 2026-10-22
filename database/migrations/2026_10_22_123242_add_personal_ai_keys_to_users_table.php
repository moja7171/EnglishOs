<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A learner's own Gemini/Groq keys, optional. Stored encrypted
        // (the model casts them), so text, not a short string.
        Schema::table('users', function (Blueprint $table) {
            $table->text('gemini_api_key')->nullable();
            $table->text('groq_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gemini_api_key', 'groq_api_key']);
        });
    }
};
