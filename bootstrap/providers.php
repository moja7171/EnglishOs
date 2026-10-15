<?php

use App\Providers\AppServiceProvider;
use NotificationChannels\WebPush\WebPushServiceProvider;

return [
    AppServiceProvider::class,
    /*
     * Listed explicitly, not left to package auto-discovery: the host serves
     * a bootstrap/cache/packages.php built before this package was added, and
     * deploys never rebuild it, so the channel's report handler was never
     * bound there and every phone alert failed to send.
     */
    WebPushServiceProvider::class,
];
