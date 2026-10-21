<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
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
    use WritesPersianNumbers;

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
        $count = $this->persianNumber($this->dueCount);

        $body = $this->dueCount === 1
            ? 'یه کلمه منتظر مروره؛ یه دقیقه کافیه.'
            : "{$count} کلمه منتظر مرورن؛ چند دقیقه بیشتر نمی‌شه.";

        return (new WebPushMessage)
            ->title('مرور کلمه‌ها')
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('review-reminder')
            ->data(['url' => route('review.index')]);
    }
}
