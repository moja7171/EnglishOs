<?php

namespace App\Notifications;

use App\Models\DirectMessage;
use Illuminate\Notifications\Notification;

class DirectMessageReceived extends Notification
{
    public function __construct(private readonly DirectMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{icon: string, title: string, url: string}
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
        ];
    }
}
