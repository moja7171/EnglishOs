<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('extra_episodes', function (Blueprint $table) {
            $table->id();
            // The episode's number in the series (1-30): every episode is open, there is no order.
            $table->unsignedSmallInteger('number')->unique();
            $table->string('title');
            // The mp4's file name on the download host (englishos/extra/<file>).
            $table->string('video_file');
            $table->unsignedSmallInteger('duration_seconds');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('extra_episodes');
    }
};
