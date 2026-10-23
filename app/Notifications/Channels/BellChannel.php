<?php

namespace App\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The bell ('database' channel, bound in AppServiceProvider). A notification
 * whose data carries a `topic` replaces the learner's earlier bell row on
 * that topic instead of stacking under it — five "you haven't studied"
 * nudges or three "a friend finished" rows read as one.
 */
class BellChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     */
    public function send($notifiable, Notification $notification): Model
    {
        $payload = $this->buildPayload($notifiable, $notification);
        $topic = $payload['data']['topic'] ?? null;

        if (is_string($topic) && $topic !== '') {
            $notifiable->notifications()->where('data->topic', $topic)->delete();
        }

        return $notifiable->routeNotificationFor('database', $notification)->create($payload);
    }
}
