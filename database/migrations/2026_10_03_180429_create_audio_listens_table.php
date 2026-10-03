<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per completed listen (the player saw at least 90% of the
        // recording played through, never skipped to). The count a learner
        // sees is simply how many rows exist for their mission + source, so
        // the same episode replayed on Listen Again days keeps adding up.
        Schema::create('audio_listens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('mission_code', 3);
            $table->string('source', 32);
            $table->timestamps();

            $table->index(['learner_id', 'mission_code', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_listens');
    }
};
