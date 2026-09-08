<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_session_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('question_index');
            $table->foreignId('responder_id')->constrained('users')->cascadeOnDelete();
            $table->string('type')->default('text'); // text | voice
            $table->text('body');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->timestamps();

            // Each person answers each question at most once — resaving
            // (retyping, re-recording) updates that same row rather than
            // ever piling up duplicates.
            // Named explicitly — the auto-generated name from these column
            // names exceeds MySQL's 64-character identifier limit (Postgres
            // only silently truncates the same long name instead of erroring).
            $table->unique(['partner_session_id', 'question_index', 'responder_id'], 'partner_session_answers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_session_answers');
    }
};
