<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // M01, M02...
            $table->string('title');
            $table->string('module');
            $table->text('outcome');
            $table->jsonb('phases')->nullable(); // ordered phase/step definitions (EOS-009 §7)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missions');
    }
};
