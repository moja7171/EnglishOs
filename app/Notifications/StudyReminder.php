<?php

namespace App\Notifications;

use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "It's your study time" — fired at one of the (up to two) times the learner
 * picked, phone only with no bell row. The wording says the time is theirs,
 * so it is clear where the alert came from, and rotates by day so the same
 * sentence doesn't greet them every time.
 */
class StudyReminder extends Notification
{
    use WritesPersianNumbers;

    /**
     * `{time}` is the learner's own chosen time, e.g. ۱۲:۰۰.
     *
     * @var list<string>
     */
    public const MESSAGES = [
        'ساعت {time} است؛ همان ساعتی که خودتان گذاشتید. ده دقیقه کافی است.',
        'یادآور {time} شما: وقت انگلیسی رسیده. یک مرحله؟',
        'این ساعت را خودتان برای مطالعه گذاشتید. شروع کنیم؟',
    ];

    /**
     * @param  string  $time  the chosen "HH:MM" this alert fires for
     */
    public function __construct(private readonly string $time, private readonly int $messageIndex = 0) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = str_replace('{time}', $this->persianNumber($this->time), self::MESSAGES[$this->messageIndex % count(self::MESSAGES)]);

        return (new WebPushMessage)
            ->title('یادآور مطالعه')
            ->body($body)
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('study-reminder')
            ->data(['url' => route('home')]);
    }
}
