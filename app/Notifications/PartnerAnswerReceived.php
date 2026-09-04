<?php

namespace App\Notifications;

use App\Models\PartnerSession;
use App\Models\User;
use Illuminate\Notifications\Notification;

class PartnerAnswerReceived extends Notification
{
    public function __construct(private readonly PartnerSession $session, private readonly User $responder) {}

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
        return [
            'icon' => 'heroicon-o-users',
            'title' => "{$this->responder->name} answered a partner session question",
            'url' => route('partner-sessions.show', $this->session),
        ];
    }
}
