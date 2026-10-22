<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per AI request made for a learner, for the admin page's
        // per-learner usage. Never holds the request or the reply.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('profile', 16)->nullable();
            $table->string('key_source', 8)->nullable();
            $table->string('outcome', 16);
            $table->string('failure_kind', 32)->nullable();
            $table->string('mission_code', 3)->nullable();
            $table->string('step_key', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
    }
};
