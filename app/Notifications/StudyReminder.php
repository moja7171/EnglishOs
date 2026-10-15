<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "It's your study time" — fired at one of the (up to two) times the learner
 * picked, phone only with no bell row. The wording rotates by day so the same
 * sentence doesn't greet them every time.
 */
class StudyReminder extends Notification
{
    /**
     * @var list<string>
     */
    public const MESSAGES = [
        'وقت انگلیسی شماست! ده دقیقه کافی است.',
        'یک قدم کوچک امروز، یک پیشرفت بزرگ فردا. بیایید انگلیسی بخوانیم.',
        'زمان مطالعه‌ای که برای خودتان گذاشته بودید رسیده است.',
        'کمی تمرین الان، خیلی راحت‌تر از جبران فردا. یک مرحله را شروع کنید؟',
        'انگلیسی منتظر شماست؛ همین حالا یک مرحله جلو بروید.',
        'همین چند دقیقه تمرین، زبان شما را هر روز قوی‌تر می‌کند.',
    ];

    public function __construct(private readonly int $messageIndex = 0) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(config('app.name', 'English OS'))
            ->body(self::MESSAGES[$this->messageIndex % count(self::MESSAGES)])
            ->icon('/icon-192.png')
            ->badge('/badge-96.png')
            ->tag('study-reminder')
            ->data(['url' => route('home')]);
    }
}
