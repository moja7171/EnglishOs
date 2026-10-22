<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * A short note an admin sends one learner from the admin page — to the
 * bell and the phone, like every other alert. The text is shown as written.
 */
class AdminMessage extends Notification
{
    use DeliversWebPush;

    /** Kept short: it is read on a lock screen and in the bell. */
    public const MAX_LENGTH = 300;

    public function __construct(private readonly string $body) {}

    /**
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-megaphone',
            'title' => $this->body,
            'url' => route('home'),
        ];
    }
}
