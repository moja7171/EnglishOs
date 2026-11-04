<?php

namespace App\Console\Commands;

use App\Services\ChatAlerts;
use Illuminate\Console\Command;

/**
 * Buzzes the phone for chat messages that had to wait for quiet hours to end
 * or the learner to leave the app. Scheduled every minute in
 * routes/console.php.
 */
class SendHeldChatAlerts extends Command
{
    protected $signature = 'chat:send-held-alerts';

    protected $description = 'Send the phone alerts of unread chat messages that had to wait';

    public function handle(ChatAlerts $alerts): int
    {
        $this->info("Sent {$alerts->sendHeld()} held chat alert(s).");

        return self::SUCCESS;
    }
}
