<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A shared, async space for two mutual-follow friends to work
        // through the SAME set of conversation questions together —
        // deliberately keyed on the Mission definition + step key, not
        // either learner's own MissionRun, since the content (see
        // Mission::conversationPrompts()) is shared curriculum content,
        // and this stays entirely outside Evidence Before Progress
        // (Article 3): neither person's mission progress is affected by
        // anything that happens here.
        Schema::create('partner_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $table->string('step_key');
            // Normalized low/high pair (see PartnerSession::findOrStartFor())
            // so the same session is found regardless of which of the two
            // friends visits first.
            $table->foreignId('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_b_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['mission_id', 'step_key', 'user_a_id', 'user_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_sessions');
    }
};
