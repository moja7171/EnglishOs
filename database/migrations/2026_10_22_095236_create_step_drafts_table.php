<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A step's work so far (answers, AI results, recordings), kept as
        // it happens so leaving the step never loses it. One row per step;
        // it becomes Evidence only when the step is saved.
        Schema::create('step_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->string('phase');
            $table->json('data');
            $table->json('paths')->nullable();
            $table->timestamps();

            $table->unique(['mission_run_id', 'phase']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('step_drafts');
    }
};
