<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "We miss you" — phone only with no bell row, sent once after 2 days away
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

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $days = $this->persianNumber($this->daysAway);
        $waiting = $this->waiting ?? 'ده دقیقه کافیه.';

        return (new WebPushMessage)
            ->title($this->daysAway <= 2 ? 'جات خالیه' : 'هنوز منتظرتیم')
            ->body($this->daysAway <= 2
                ? "{$days} روزه نیومدی. {$waiting}"
                : "از همون‌جا ادامه بده: {$waiting}")
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('comeback-reminder')
            ->data(['url' => route('home')]);
    }
}
