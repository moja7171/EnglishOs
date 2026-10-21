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

    /**
     * @param  string|null  $waiting  what's waiting, from Reminders::whatsWaiting()
     */
    public function __construct(private readonly int $streakDays, private readonly ?string $waiting = null) {}

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

        $body = ($this->streakDays === 1 ? 'زنجیره‌ات امشب می‌شکنه.' : "زنجیرهٔ {$days} روزه‌ات امشب می‌شکنه.")
            .' '.($this->waiting ?? 'یه مرحله کافیه.');

        return (new WebPushMessage)
            ->title('زنجیره در خطر 🔥')
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('streak-warning')
            ->data(['url' => route('home')]);
    }
}
