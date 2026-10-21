<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "It's your study time" — fired at one of the (up to two) times the learner
 * picked, phone only with no bell row. The title names their own time, so
 * it is clear where the alert came from, and the body says exactly what is
 * waiting (see Reminders::whatsWaiting()) plus any words due for review.
 */
class StudyReminder extends Notification
{
    use WritesPersianNumbers;

    /** When there is nothing specific to name (e.g. the roadmap is done). */
    public const FALLBACK_MESSAGE = 'وقت انگلیسیه؛ ده دقیقه کافیه.';

    /**
     * @param  string  $time  the chosen "HH:MM" this alert fires for
     * @param  string|null  $waiting  what's waiting, from Reminders::whatsWaiting()
     */
    public function __construct(
        private readonly string $time,
        private readonly ?string $waiting = null,
        private readonly int $dueReviews = 0,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = $this->waiting ?? self::FALLBACK_MESSAGE;

        if ($this->dueReviews > 0) {
            $body .= "\n+ {$this->persianNumber($this->dueReviews)} کلمه برای مرور";
        }

        return (new WebPushMessage)
            ->title("یادآور ساعت {$this->persianNumber($this->time)}")
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('study-reminder')
            ->data(['url' => route('home')]);
    }
}
