<?php

namespace App\Notifications;

use App\Models\DirectMessage;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

class DirectMessageReceived extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly DirectMessage $message) {}

    /**
     * One lock-screen alert per sender: a burst of messages replaces the
     * previous alert (and re-buzzes) instead of stacking.
     */
    protected function webPushTag(): ?string
    {
        return "dm-{$this->message->sender_id}";
    }

    /**
     * `sender_id` and `kind` let the conversation collapse a burst of
     * messages into one notification per sender (see
     * ⚡conversation.blade.php) without matching on the title or URL.
     *
     * @return array{icon: string, title: string, url: string, sender_id: int, kind: string}
     */
    public function toArray(object $notifiable): array
    {
        $sender = $this->message->sender;

        $title = $this->message->type === DirectMessage::TYPE_NUDGE
            ? "{$sender->name} sent you a nudge"
            : "{$sender->name} sent you a message";

        return [
            'icon' => $this->message->type === DirectMessage::TYPE_NUDGE ? 'heroicon-s-fire' : 'heroicon-o-chat-bubble-left-right',
            'title' => $title,
            'url' => route('friends.conversation', $sender),
            'sender_id' => $sender->id,
            'kind' => $this->message->type === DirectMessage::TYPE_NUDGE ? DirectMessage::TYPE_NUDGE : DirectMessage::TYPE_MESSAGE,
        ];
    }
}
