<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shadowing recording kept the moment it is made, so leaving the
        // step never loses it. It becomes Evidence only when the step is
        // saved; one row per line, a new take replaces the old one.
        Schema::create('shadow_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->string('phase');
            $table->unsignedSmallInteger('line_index');
            $table->string('path');
            $table->json('feedback')->nullable();
            $table->timestamps();

            $table->unique(['mission_run_id', 'phase', 'line_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shadow_drafts');
    }
};
