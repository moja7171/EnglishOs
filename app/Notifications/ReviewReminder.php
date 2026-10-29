<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The daily "come do your review" nudge, kept in the bell as the one
 * daily-nudge row (see StudyReminder::TOPIC). It goes through the push channel directly (not the deferred one)
 * because it is sent from a scheduled command, not from a web request.
 */
class ReviewReminder extends Notification
{
    use WritesPersianNumbers;

    public function __construct(private readonly int $dueCount, private readonly bool $forWords = true) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * @return array{icon: string, title: string, url: string, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-arrow-path',
            'title' => $this->title().' — '.$this->body(),
            'url' => route('review.index'),
            'topic' => StudyReminder::TOPIC,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('review-reminder')
            ->data([
                'url' => route('review.index'),
                'id' => $notification->id,
                'unread' => $notifiable->unreadNotifications()->count(),
            ]);
    }

    private function title(): string
    {
        return $this->forWords ? 'مرور کلمه‌ها' : 'مرور گفتار و گرامر';
    }

    private function body(): string
    {
        if (! $this->forWords) {
            return $this->dueCount === 1
                ? 'یه تمرین گفتار و گرامر منتظره؛ یه دقیقه کافیه.'
                : "{$this->persianNumber($this->dueCount)} تمرین گفتار و گرامر منتظره؛ چند دقیقه بیشتر نمی‌شه.";
        }

        return $this->dueCount === 1
            ? 'یه کلمه منتظر مروره؛ یه دقیقه کافیه.'
            : "{$this->persianNumber($this->dueCount)} کلمه منتظر مرورن؛ چند دقیقه بیشتر نمی‌شه.";
    }
}
