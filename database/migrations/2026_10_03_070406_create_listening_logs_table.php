<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per learner per calendar day: the learner can stay on the
        // same program day for several calendar days, and each of those days
        // can carry its own "I listened" tick — but ticking twice on the
        // same day is the same fact, so the unique key makes it idempotent.
        // mission_code/day_number only record where in the program they
        // were; the streak only ever reads listened_on.
        Schema::create('listening_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            $table->date('listened_on');
            $table->string('mission_code', 3);
            $table->unsignedTinyInteger('day_number');
            $table->timestamps();

            $table->unique(['learner_id', 'listened_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_logs');
    }
};
