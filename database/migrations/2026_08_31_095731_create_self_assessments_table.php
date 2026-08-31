<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->string('skill'); // listening, vocabulary, grammar, speaking, writing
            $table->unsignedTinyInteger('before')->nullable(); // 1-5
            $table->unsignedTinyInteger('after')->nullable(); // 1-5
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_assessments');
    }
};
