<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "We miss you" — kept in the bell as the one daily-nudge row (see
 * StudyReminder::TOPIC), sent once after 2 days away
 * and once after 5 (see Reminders::COMEBACK_DAYS), naming exactly where to
 * pick up so coming back feels small.
 */
class ComebackReminder extends Notification
{
    use WritesPersianNumbers;

    /**
     * @param  string|null  $waiting  what's waiting, from Reminders::whatsWaiting()
     */
    public function __construct(private readonly int $daysAway, private readonly ?string $waiting = null) {}

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
            'icon' => 'heroicon-s-heart',
            'title' => $this->title().' — '.$this->body(),
            'url' => route('home'),
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
            ->tag('comeback-reminder')
            ->data([
                'url' => route('home'),
                'id' => $notification->id,
                'unread' => $notifiable->unreadNotifications()->count(),
            ]);
    }

    private function title(): string
    {
        return $this->daysAway <= 2 ? 'جات خالیه' : 'هنوز منتظرتیم';
    }

    private function body(): string
    {
        $waiting = $this->waiting ?? 'ده دقیقه کافیه.';

        return $this->daysAway <= 2
            ? "{$this->persianNumber($this->daysAway)} روزه نیومدی. {$waiting}"
            : "از همون‌جا ادامه بده: {$waiting}";
    }
}
