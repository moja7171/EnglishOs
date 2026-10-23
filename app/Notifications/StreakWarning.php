<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use App\Services\StreakRing;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "1 more activity keeps your 4-day streak — a 3-minute review" — kept in
 * the bell as the one daily-nudge row (see StudyReminder::TOPIC). Sent
 * once, in the evening, to a learner with a streak whose ring today is
 * still short of StreakRing::SLICES_TO_KEEP, naming the quickest thing to do.
 */
class StreakWarning extends Notification
{
    use WritesPersianNumbers;

    /**
     * @param  string|null  $quickest  the quickest next activity, from Reminders::quickestNext()
     * @param  int  $activitiesLeft  slices still missing today
     */
    public function __construct(
        private readonly int $streakDays,
        private readonly ?string $quickest = null,
        private readonly int $activitiesLeft = StreakRing::SLICES_TO_KEEP,
    ) {}

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
            'icon' => 'heroicon-s-fire',
            'title' => 'زنجیره در خطر 🔥 '.$this->body(),
            'url' => route('home'),
            'topic' => StudyReminder::TOPIC,
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('زنجیره در خطر 🔥')
            ->body($this->body())
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('streak-warning')
            ->data([
                'url' => route('home'),
                'id' => $notification->id,
                'unread' => $notifiable->unreadNotifications()->count(),
            ]);
    }

    private function body(): string
    {
        $left = $this->persianNumber(max(1, $this->activitiesLeft));
        $days = $this->persianNumber($this->streakDays);

        return "{$left} کار دیگه تا حفظ زنجیرهٔ {$days} روزه‌ات"
            .($this->quickest !== null ? " — {$this->quickest}" : '');
    }
}
