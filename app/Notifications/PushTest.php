<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The "Send a test" button in the bell. Never stored in the bell and never
 * routed through `notify()` — the button calls the push channel directly so
 * it can report whether the push service really accepted the message.
 */
class PushTest extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body('Phone alerts are working on this device.')
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->data(['url' => url('/')]);
    }
}
