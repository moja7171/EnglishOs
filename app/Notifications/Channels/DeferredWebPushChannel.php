<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * Sends the phone push after the response has gone out instead of inside the
 * request that triggered it. Delivery is a blocking HTTP call to the browser's
 * push service (FCM, Apple, Mozilla) — from a server that struggles to reach
 * those, it can take its whole timeout, and a learner sending a message must
 * never wait on that. `always: true` because a redirect or error response
 * would otherwise skip the deferred callback.
 */
class DeferredWebPushChannel
{
    public function __construct(private readonly WebPushChannel $channel) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        defer(function () use ($notifiable, $notification): void {
            try {
                $this->channel->send($notifiable, $notification);
            } catch (Throwable $e) {
                // The bell already has the notification; a push failure
                // must never surface to (or break) the learner's request.
                report($e);
            }
        }, always: true);
    }
}
