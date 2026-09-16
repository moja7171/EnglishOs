<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per attempt, not one per learner: the same test is meant
        // to be retaken at the end of the 120 days, and the before/after
        // pair is the point (see App\Services\PlacementTest).
        Schema::create('placement_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->string('level');
            $table->string('recognition_level');
            $table->string('spoken_level')->nullable();
            $table->text('transcript')->nullable();
            $table->json('detail');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('placement_tests');
    }
};
