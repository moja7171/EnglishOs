<?php

namespace App\Notifications;

use App\Models\PartnerSession;
use App\Models\User;
use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

class PartnerAnswerReceived extends Notification
{
    use DeliversWebPush;

    public function __construct(private readonly PartnerSession $session, private readonly User $responder) {}

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
