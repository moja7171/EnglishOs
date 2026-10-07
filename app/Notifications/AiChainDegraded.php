<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversWebPush;
use Illuminate\Notifications\Notification;

/**
 * Tells admins an AI model chain is down to its last working model (or has
 * none left), so quota running out stops being invisible. Sent through the
 * bell and the phone push channel, like every other alert.
 */
class AiChainDegraded extends Notification
{
    use DeliversWebPush;

    public function __construct(
        private readonly string $chainName,
        private readonly string $lastModel,
        private readonly bool $everyModelDown,
    ) {}

    /**
     * @return array{icon: string, title: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'icon' => 'heroicon-s-exclamation-triangle',
            'title' => $this->everyModelDown
                ? "AI models: every {$this->chainName} model is down right now."
                : "AI models: {$this->chainName} is down to its last model ({$this->lastModel}).",
            'url' => route('profile'),
        ];
    }

    protected function webPushTag(): ?string
    {
        return 'ai-chain-'.md5($this->chainName);
    }
}
