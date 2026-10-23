<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per thing a learner finished on one of their local days —
        // a slice of that day's streak ring (see App\Services\StreakRing).
        // Kept as they happen: a review's date is overwritten by the next
        // review, so it can't be worked out again later.
        Schema::create('day_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->date('on_date');
            $table->string('activity', 100);
            $table->timestamps();

            $table->unique(['learner_id', 'on_date', 'activity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_activities');
    }
};
