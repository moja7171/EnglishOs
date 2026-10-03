<?php

namespace NotificationChannels\WebPush;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Config;
use Minishlink\WebPush\ContentEncoding;

trait HasPushSubscriptions
{
    /**
     *  Get all of the subscriptions.
     *
     * @return MorphMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): MorphMany
    {
        /** @var class-string<PushSubscription> $model */
        $model = Config::string('webpush.model');

        return $this->morphMany($model, 'subscribable');
    }

    /**
     * Update (or create) subscription.
     */
    public function updatePushSubscription(string $endpoint, ?string $key = null, ?string $token = null, ContentEncoding|string|null $contentEncoding = null): PushSubscription
    {
        if (is_string($contentEncoding)) {
            $contentEncoding = ContentEncoding::from($contentEncoding);
        }

        /** @var class-string<PushSubscription> $model */
        $model = Config::string('webpush.model');
        $subscription = $model::findByEndpoint($endpoint);

        if ($subscription && $this->ownsPushSubscription($subscription)) {
            $subscription->public_key = $key;
            $subscription->auth_token = $token;
            $subscription->content_encoding = $contentEncoding;
            $subscription->save();

            return $subscription;
        }

        if ($subscription && ! $this->ownsPushSubscription($subscription)) {
            $subscription->delete();
        }

        return $this->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => $key,
            'auth_token' => $token,
            'content_encoding' => $contentEncoding,
        ]);
    }

    /**
     * Determine if the model owns the given subscription.
     */
    public function ownsPushSubscription(PushSubscription $subscription): bool
    {
        $key = $this->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return false;
        }

        return (string) $subscription->subscribable_id === (string) $key &&
                        $subscription->subscribable_type === $this->getMorphClass();
    }

    /**
     * Delete subscription by endpoint.
     */
    public function deletePushSubscription(string $endpoint): void
    {
        $this->pushSubscriptions()
            ->where('endpoint', $endpoint)
            ->delete();
    }

    /**
     * Get all of the subscriptions.
     *
     * @return Collection<array-key, PushSubscription>
     */
    public function routeNotificationForWebPush(): Collection
    {
        return $this->pushSubscriptions;
    }
}
