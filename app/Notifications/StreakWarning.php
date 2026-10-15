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
            ? 'زنجیرهٔ یادگیری شما در خطر است! امروز یک مرحله کار کنید یا تیک «گوش دادم» را بزنید تا از دست نرود.'
            : "زنجیرهٔ {$days} روزهٔ شما در خطر است! امروز یک مرحله کار کنید یا تیک «گوش دادم» را بزنید تا از دست نرود.";

        return (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('streak-warning')
            ->data(['url' => route('home')]);
    }
}
