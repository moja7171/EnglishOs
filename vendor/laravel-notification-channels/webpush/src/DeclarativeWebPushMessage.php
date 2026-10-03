<?php

declare(strict_types=1);

namespace NotificationChannels\WebPush;

use Illuminate\Support\Arr;
use NotificationChannels\WebPush\Exceptions\MessageValidationFailed;

/**
 * @link https://www.w3.org/TR/push-api/#members
 */
class DeclarativeWebPushMessage implements WebPushMessageInterface
{
    protected ?string $title = null;

    /**
     * @var array<array-key, array{'title': string, 'action': string, 'navigate': string, 'icon'?: string}>
     */
    protected array $actions = [];

    protected ?string $badge = null;

    protected ?string $body = null;

    protected ?string $dir = null;

    protected ?string $icon = null;

    protected ?string $image = null;

    protected ?string $lang = null;

    protected ?bool $mutable = null;

    protected ?string $navigate = null;

    protected ?bool $renotify = null;

    protected ?bool $requireInteraction = null;

    protected ?bool $silent = null;

    protected ?string $tag = null;

    protected ?int $timestamp = null;

    /**
     * @var array<int>
     */
    protected array $vibrate = [];

    protected mixed $data = null;

    /**
     * @var array<string, mixed>
     */
    protected array $options = [
        'contentType' => 'application/json',
    ];

    /**
     * Set the notification title.
     */
    public function title(string $value): static
    {
        $this->title = $value;

        return $this;
    }

    /**
     * Add a notification action.
     */
    public function action(string $title, string $action, string $navigate, ?string $icon = null): static
    {
        $notification_action = ['title' => $title, 'action' => $action, 'navigate' => $navigate];

        if ($icon !== null) {
            $notification_action['icon'] = $icon;
        }

        $this->actions[] = $notification_action;

        return $this;
    }

    /**
     * Set the notification badge.
     */
    public function badge(string $value): static
    {
        $this->badge = $value;

        return $this;
    }

    /**
     * Set the notification body.
     */
    public function body(string $value): static
    {
        $this->body = $value;

        return $this;
    }

    /**
     * Set the notification direction.
     */
    public function dir(string $value): static
    {
        $this->dir = $value;

        return $this;
    }

    /**
     * Set the notification icon url.
     */
    public function icon(string $value): static
    {
        $this->icon = $value;

        return $this;
    }

    /**
     * Set the notification image url.
     */
    public function image(string $value): static
    {
        $this->image = $value;

        return $this;
    }

    /**
     * Set the notification language.
     */
    public function lang(string $value): static
    {
        $this->lang = $value;

        return $this;
    }

    public function mutable(bool $value = true): static
    {
        $this->mutable = $value;

        return $this;
    }

    /**
     * Set the navigation target upon activation.
     */
    public function navigate(string $value): static
    {
        $this->navigate = $value;

        return $this;
    }

    public function renotify(bool $value = true): static
    {
        $this->renotify = $value;

        return $this;
    }

    public function requireInteraction(bool $value = true): static
    {
        $this->requireInteraction = $value;

        return $this;
    }

    public function silent(bool $value = true): static
    {
        $this->silent = $value;

        return $this;
    }

    /**
     * Set the notification tag.
     */
    public function tag(string $value): static
    {
        $this->tag = $value;

        return $this;
    }

    /**
     * Set the timestamp associated with the notification.
     */
    public function timestamp(int $value): static
    {
        $this->timestamp = $value;

        return $this;
    }

    /**
     * Set the notification vibration pattern.
     *
     * @param  array<int>  $value
     */
    public function vibrate(array $value): static
    {
        $this->vibrate = $value;

        return $this;
    }

    /**
     * Set the notification arbitrary data.
     */
    public function data(mixed $value): static
    {
        $this->data = $value;

        return $this;
    }

    /**
     * Set the notification options.
     *
     * @link https://github.com/web-push-libs/web-push-php#notifications-and-default-options
     *
     * @param  array<string, mixed>  $value
     */
    public function options(array $value): static
    {
        $this->options = $value;

        return $this;
    }

    /**
     * Get the notification options.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Get an array representation of the message.
     *
     * @return array{web_push: int, notification: array<string, mixed>, mutable?: bool}
     */
    public function toArray(): array
    {
        if (blank($this->title)) {
            throw MessageValidationFailed::titleRequired();
        }

        if (blank($this->navigate)) {
            throw MessageValidationFailed::navigateRequired();
        }

        $payload = [
            'web_push' => 8030,
            'notification' => Arr::except(array_filter(get_object_vars($this)), ['mutable', 'options']),
        ];

        if ($this->mutable !== null) {
            $payload['mutable'] = $this->mutable;
        }

        return $payload;
    }
}
