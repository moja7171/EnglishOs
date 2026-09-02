<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every exchange with "Ask the AI Instructor" (see ⚡ask-instructor.blade.php)
        // scoped to the LEARNER, not one mission run — this is a standing,
        // growing record of everything a learner has ever asked, kept so a
        // future feature (coaching summaries, cross-mission feedback, etc.)
        // can mine it, not just something the current panel displays.
        Schema::create('instructor_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learner_id')->constrained('users')->cascadeOnDelete();
            // Nullable: only for context (which step this was asked from) —
            // a message must never become unreadable just because its run
            // was later deleted.
            $table->foreignId('mission_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('step_key')->nullable();
            $table->string('role'); // 'learner' or 'instructor'
            $table->text('body');
            $table->string('type')->default('text'); // text | voice | file
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instructor_messages');
    }
};
