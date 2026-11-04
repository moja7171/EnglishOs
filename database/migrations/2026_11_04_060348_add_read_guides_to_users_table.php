<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The keys of the usage guides (config/usage_guides.php) the
        // learner has confirmed with «خواندم»; those never open by themselves again.
        Schema::table('users', function (Blueprint $table) {
            $table->json('read_guides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('read_guides');
        });
    }
};
