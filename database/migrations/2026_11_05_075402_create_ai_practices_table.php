<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_practices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->json('days')->nullable();
            $table->json('deck');
            $table->string('stage', 16);
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('current')->nullable();
            $table->unsignedSmallInteger('answered')->default(0);
            $table->unsignedSmallInteger('score')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['learner_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_practices');
    }
};
