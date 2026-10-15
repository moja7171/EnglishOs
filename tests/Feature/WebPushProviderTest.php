<?php

namespace Tests\Feature;

use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushServiceProvider;
use Tests\TestCase;

class WebPushProviderTest extends TestCase
{
    public function test_the_push_channel_can_be_built_from_the_container(): void
    {
        $this->assertInstanceOf(WebPushChannel::class, app(WebPushChannel::class));
    }

    /**
     * A stale bootstrap/cache/packages.php on the host hides auto-discovered
     * providers, so this one must not depend on discovery.
     */
    public function test_the_provider_is_registered_explicitly_not_only_by_discovery(): void
    {
        $this->assertContains(WebPushServiceProvider::class, require base_path('bootstrap/providers.php'));
    }
}
