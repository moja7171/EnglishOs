<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\DeferredWebPushChannel;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Adds the phone push next to the bell for a notification that already
 * defines `toArray()` with `title` and `url` — the push reuses those, so
 * the bell row and the lock-screen alert always say the same thing.
 */
trait DeliversWebPush
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', DeferredWebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        /** @var array{title: string, url: string} $data */
        $data = $this->toArray($notifiable);

        $message = (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body($data['title'])
            ->icon('/icon-192.png')
            ->data(['url' => $data['url']]);

        $tag = $this->webPushTag();

        if ($tag !== null) {
            // Same tag replaces the earlier alert instead of stacking, and
            // renotify still makes the phone buzz for the newer one.
            $message->tag($tag)->renotify();
        }

        return $message;
    }

    /**
     * Override to collapse a burst of alerts into one on the lock screen.
     */
    protected function webPushTag(): ?string
    {
        return null;
    }
}
