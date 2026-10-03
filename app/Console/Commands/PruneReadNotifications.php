<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Keeps the notifications table from growing forever — the bell never shows
 * more than the latest few, and read ones have no value as history.
 * Scheduled daily in routes/console.php.
 */
class PruneReadNotifications extends Command
{
    protected $signature = 'notifications:prune-read {--days=30 : Delete read notifications older than this many days}';

    protected $description = 'Delete read database notifications older than the given number of days';

    public function handle(): int
    {
        $deleted = DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays((int) $this->option('days')))
            ->delete();

        $this->info("Deleted {$deleted} read notification(s).");

        return self::SUCCESS;
    }
}
