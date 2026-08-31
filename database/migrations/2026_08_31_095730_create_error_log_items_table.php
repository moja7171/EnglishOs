<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_log_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->text('error');
            $table->text('correction');
            $table->text('new_example')->nullable(); // learner-written corrected sentence
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_log_items');
    }
};
