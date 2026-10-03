<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The daily "come do your review" nudge — phone only, with no bell row: the
 * review badge in the nav already says the same thing once the learner is in
 * the app. It goes through the push channel directly (not the deferred one)
 * because it is sent from a scheduled command, not from a web request.
 */
class ReviewReminder extends Notification
{
    public function __construct(private readonly int $dueCount) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = $this->dueCount === 1
            ? '1 thing is ready to review — it only takes a minute.'
            : "{$this->dueCount} things are ready to review — it only takes a few minutes.";

        return (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('review-reminder')
            ->data(['url' => route('review.index')]);
    }
}
