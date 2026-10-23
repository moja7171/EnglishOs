<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Services\Reminders;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Adds the phone push next to the bell for a notification that already
 * defines `toArray()` with `title` and `url` — the push reuses the bell
 * row, so the two always say the same thing, and tapping the push marks
 * that row read (see MarkNotificationRead).
 */
trait DeliversWebPush
{
    /**
     * Bell only — no buzz — during the learner's quiet hours (see
     * User::isInQuietHours()), while they have the app open on screen (the
     * bell shows it as a banner there), and for news once today's alerts
     * are used up (see Reminders::DAILY_LIMIT) — unless the notification
     * can't wait.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User || ! $this->silentInQuietHours()) {
            return ['database', DeferredWebPushChannel::class];
        }

        if ($notifiable->isInQuietHours() || $notifiable->isInApp()) {
            return ['database'];
        }

        if ($this->countsTowardDailyLimit()) {
            $reminders = app(Reminders::class);

            if (! $reminders->hasRoomToday($notifiable, keepFree: Reminders::KEPT_FOR_REMINDERS)) {
                return ['database'];
            }

            $reminders->countSent($notifiable);
        }

        return ['database', DeferredWebPushChannel::class];
    }

    /**
     * False for an alert that is only useful right now, like a live Pair
     * Practice invite.
     */
    protected function silentInQuietHours(): bool
    {
        return true;
    }

    /**
     * True for automatic news about the learner's day (not a message or an
     * invite from a person), which shares the daily limit with reminders.
     */
    protected function countsTowardDailyLimit(): bool
    {
        return false;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        // The bell row as saved — a topic row may have merged earlier ones.
        /** @var array{title: string, url: string} $data */
        $data = $notifiable->notifications()->find($notification->id)->data ?? $this->toArray($notifiable);

        $message = (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body($data['title'])
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->data([
                'url' => $data['url'],
                'id' => $notification->id,
                'unread' => $notifiable->unreadNotifications()->count(),
            ]);

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
