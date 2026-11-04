<?php

namespace App\Services;

use App\Models\DirectMessage;
use App\Models\User;
use App\Notifications\Channels\DeferredWebPushChannel;
use App\Notifications\DirectMessageReceived;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Tells a friend about a message. A burst stays one bell row per sender: the
 * row moves to the top instead of stacking, until the conversation is read.
 * The phone follows that row: it buzzes for the first message and again once
 * a few minutes have passed, never once per message. A message that can't
 * buzz right now (quiet hours, the app on screen) is held on its row, so
 * nothing later can stay silent: sendHeld() buzzes it once the learner can be
 * reached, unless it has been read by then.
 */
class ChatAlerts
{
    /** The phone buzzes at most once in this long for one sender's burst. */
    public const REPEAT_MINUTES = 3;

    /** A held alert older than this is no longer news. */
    public const HELD_FOR_HOURS = 24;

    public function deliver(DirectMessage $message, User $recipient): void
    {
        if ($recipient->hasMuted($message->sender)) {
            return;
        }

        $notification = new DirectMessageReceived($message);
        $reachesPhone = $this->reachesPhone($notification, $recipient);
        $row = $this->unreadRow($message, $recipient);

        if ($row === null) {
            $notification->id = (string) Str::uuid();
            $recipient->notify($notification);

            if ($reachesPhone) {
                Cache::put($this->repeatKey($message), true, now()->addMinutes(self::REPEAT_MINUTES));
            } else {
                $this->hold($recipient->notifications()->findOrFail($notification->id));
            }

            return;
        }

        $row->forceFill(['created_at' => now()])->save();

        if (! $reachesPhone) {
            $this->hold($row);
        } elseif (Cache::add($this->repeatKey($message), true, now()->addMinutes(self::REPEAT_MINUTES))) {
            $this->push($notification, $row, $recipient);
        }
    }

    /**
     * Buzzes every held alert whose learner can be reached now. A read row
     * is left out: the learner has already seen the message.
     *
     * @return int How many went out.
     */
    public function sendHeld(): int
    {
        $sent = 0;

        DatabaseNotification::query()
            ->where('type', DirectMessageReceived::class)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subHours(self::HELD_FOR_HOURS))
            ->with('notifiable')
            ->get()
            ->filter(fn (DatabaseNotification $row) => $row->data['push_held'] ?? false)
            ->each(function (DatabaseNotification $row) use (&$sent): void {
                $recipient = $row->notifiable;
                $message = $recipient instanceof User ? $this->latestMessage($row, $recipient) : null;

                if ($message === null || $recipient->hasMuted($message->sender)) {
                    $this->release($row);

                    return;
                }

                $notification = new DirectMessageReceived($message);

                if (! $this->reachesPhone($notification, $recipient)) {
                    return;
                }

                Cache::put($this->repeatKey($message), true, now()->addMinutes(self::REPEAT_MINUTES));
                $this->push($notification, $row, $recipient);
                $sent++;
            });

        return $sent;
    }

    private function reachesPhone(DirectMessageReceived $notification, User $recipient): bool
    {
        return in_array(DeferredWebPushChannel::class, $notification->via($recipient), true);
    }

    private function unreadRow(DirectMessage $message, User $recipient): ?DatabaseNotification
    {
        return $recipient->unreadNotifications()
            ->where('type', DirectMessageReceived::class)
            ->where('data->sender_id', $message->sender_id)
            ->where('data->kind', $this->kind($message))
            ->first();
    }

    private function latestMessage(DatabaseNotification $row, User $recipient): ?DirectMessage
    {
        return DirectMessage::query()
            ->where('sender_id', $row->data['sender_id'])
            ->where('recipient_id', $recipient->id)
            ->latest('id')
            ->first();
    }

    /**
     * The push carries the row's id, so tapping it marks that row read (see
     * MarkNotificationRead).
     */
    private function push(DirectMessageReceived $notification, DatabaseNotification $row, User $recipient): void
    {
        $notification->id = $row->id;
        $recipient->notifyNow($notification, [DeferredWebPushChannel::class]);

        $this->release($row);
    }

    private function hold(DatabaseNotification $row): void
    {
        $row->forceFill(['data' => [...$row->data, 'push_held' => true]])->save();
    }

    private function release(DatabaseNotification $row): void
    {
        if (isset($row->data['push_held'])) {
            $row->forceFill(['data' => collect($row->data)->except('push_held')->all()])->save();
        }
    }

    private function kind(DirectMessage $message): string
    {
        return $message->type === DirectMessage::TYPE_NUDGE ? DirectMessage::TYPE_NUDGE : DirectMessage::TYPE_MESSAGE;
    }

    private function repeatKey(DirectMessage $message): string
    {
        return "chat-alert:{$message->recipient_id}:{$message->sender_id}:{$this->kind($message)}";
    }
}
