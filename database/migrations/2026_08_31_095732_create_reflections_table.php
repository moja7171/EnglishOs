<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reflections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->jsonb('answers'); // {"what_became_easier": "...", "still_difficult": "...", ...}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reflections');
    }
};
