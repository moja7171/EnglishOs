<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keys are required of every learner now, not just new sign-ups. Admins
     * stay free. There is no way back: down() could not tell these learners
     * from the ones who signed up after keys became required.
     */
    public function up(): void
    {
        DB::table('users')->where('is_admin', false)->update(['ai_keys_required' => true]);
    }

    public function down(): void
    {
        //
    }
};
