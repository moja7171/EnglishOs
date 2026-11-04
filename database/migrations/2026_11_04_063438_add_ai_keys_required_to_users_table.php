<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set at sign-up from now on: this learner stays on the keys page
        // until both AI keys are saved. Earlier accounts stay false.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ai_keys_required')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_keys_required');
        });
    }
};
