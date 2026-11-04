<?php

use App\Services\Usernames;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', Usernames::MAX_LENGTH)->nullable();
            $table->string('invite_code', Usernames::INVITE_CODE_LENGTH)->nullable();
        });

        // Earlier accounts: the username comes from the name, and the oldest
        // account keeps the clean one when two share a name.
        $usernames = app(Usernames::class);

        DB::table('users')->orderBy('id')->get(['id', 'name'])->each(function (object $user) use ($usernames) {
            DB::table('users')->where('id', $user->id)->update([
                'username' => $usernames->forName($user->name),
                'invite_code' => $usernames->newInviteCode(),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
            $table->unique('invite_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['invite_code']);
            $table->dropColumn(['username', 'invite_code']);
        });
    }
};
