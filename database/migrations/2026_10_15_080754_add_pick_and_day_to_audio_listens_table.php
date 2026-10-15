<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // day_number says which of a mission's four days a listen happened
        // on (the same recording is replayed on all four); pick_key names
        // one of the three daily picks on /listening ("{day}-{level}") so
        // each pick keeps its own count; self_reported marks the learner's
        // own "I listened to this one" tick on a pick that plays outside
        // the app, which can never be verified like an in-app listen.
        // Rows from before this change keep NULL / false.
        Schema::table('audio_listens', function (Blueprint $table) {
            $table->unsignedTinyInteger('day_number')->nullable()->after('source');
            $table->string('pick_key', 8)->nullable()->after('day_number');
            $table->boolean('self_reported')->default(false)->after('pick_key');

            $table->index(['learner_id', 'mission_code', 'source', 'pick_key'], 'audio_listens_pick_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('audio_listens', function (Blueprint $table) {
            $table->dropIndex('audio_listens_pick_lookup');
            $table->dropColumn(['day_number', 'pick_key', 'self_reported']);
        });
    }
};
