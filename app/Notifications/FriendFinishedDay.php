<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use App\Notifications\Concerns\WritesPersianNumbers;
use Illuminate\Notifications\Notification;

/**
 * A mutual friend just finished their mission day and this learner hasn't
 * yet — at most once per friend per day (see DayFinishedAlerts). Friends
 * finishing the same day share one bell row and one alert: "Sara, Ali and
 * 1 more finished".
 */
class FriendFinishedDay extends Notification
{
    use DeliversWebPush;
    use WritesPersianNumbers;

    public function __construct(private readonly User $friend) {}

    /** The bell row all of today's "a friend finished" alerts share. */
    public const TOPIC = 'friends-finished';

    /**
     * @return array{icon: string, title: string, url: string, sender_id: int, names: list<string>, topic: string}
     */
    public function toArray(object $notifiable): array
    {
        $earlier = $notifiable->unreadNotifications()
            ->where('type', self::class)
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->flatMap(fn ($row) => $row->data['names'] ?? [])
            ->all();

        $names = collect([$this->friend->name, ...$earlier])->unique()->values()->all();

        return [
            'icon' => 'heroicon-s-fire',
            'title' => 'نوبت توئه 👋 '.$this->whoFinished($names),
            'url' => route('home'),
            'sender_id' => $this->friend->id,
            'names' => $names,
            'topic' => self::TOPIC,
        ];
    }

    /**
     * @param  list<string>  $names  newest first
     */
    private function whoFinished(array $names): string
    {
        return match (count($names)) {
            1 => "{$names[0]} درس امروزش رو تموم کرد",
            2 => "{$names[0]} و {$names[1]} درس امروزشون رو تموم کردن",
            default => "{$names[0]}، {$names[1]} و {$this->persianNumber(count($names) - 2)} نفر دیگه درس امروزشون رو تموم کردن",
        };
    }

    protected function countsTowardDailyLimit(): bool
    {
        return true;
    }

    protected function webPushTag(): ?string
    {
        return self::TOPIC;
    }
}
