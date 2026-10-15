<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your streak breaks if you skip today" — phone only with no bell row. Sent
 * once, in the evening, to a learner whose chain is still alive but who has
 * done nothing yesterday or today.
 */
class StreakWarning extends Notification
{
    use WritesPersianNumbers;

    public function __construct(private readonly int $streakDays) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $days = $this->persianNumber($this->streakDays);

        $body = $this->streakDays === 1
            ? 'زنجیرهٔ یادگیری‌تان در خطر است؛ امروز یک مرحله کار کنید.'
            : "زنجیرهٔ {$days} روزه‌تان در خطر است؛ امروز یک مرحله کار کنید.";

        return (new WebPushMessage)
            ->title('حفظ زنجیره')
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('streak-warning')
            ->data(['url' => route('home')]);
    }
}
