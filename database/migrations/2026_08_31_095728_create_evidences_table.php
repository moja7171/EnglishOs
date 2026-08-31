<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_run_id')->constrained()->cascadeOnDelete();
            $table->string('phase'); // e.g. mission_brief, activation, conversation_1...
            $table->string('type'); // audio | text | transcript | score
            $table->text('content_ref'); // storage path, URL, or inline text
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidences');
    }
};
