<?php

use App\Notifications\PushTest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;

new class extends Component
{
    public string $testResult = '';

    public bool $testFailed = false;

    /**
     * Called by the browser right after it subscribes to its push service.
     * The subscription is always saved against the signed-in user, never
     * against an id the client sends; the same endpoint re-saved just updates
     * its keys, and an endpoint last held by someone else on a shared device
     * moves to this user.
     */
    public function savePushSubscription(string $endpoint, string $key, string $token, ?string $encoding = null): void
    {
        Validator::make(
            ['endpoint' => $endpoint, 'key' => $key, 'token' => $token, 'encoding' => $encoding],
            [
                'endpoint' => ['required', 'url:https', 'max:'.PushSubscription::ENDPOINT_MAX_LENGTH],
                'key' => ['required', 'string', 'max:255'],
                'token' => ['required', 'string', 'max:255'],
                'encoding' => ['nullable', 'in:aes128gcm,aesgcm'],
            ],
        )->validate();

        $user = auth()->user();

        $user->updatePushSubscription($endpoint, $key, $token, $encoding ?? 'aes128gcm');
        $user->rememberTimezone();
    }

    public function removePushSubscription(string $endpoint): void
    {
        auth()->user()->deletePushSubscription($endpoint);
    }

    /**
     * Sends straight through the push channel (not via notify()) so the
     * learner learns whether the push service really accepted it — on a
     * network that blocks it, this is the only place the failure is visible.
     */
    public function sendTest(): void
    {
        $user = auth()->user();

        $this->testFailed = true;

        if ($user->pushSubscriptions()->doesntExist()) {
            $this->testResult = 'No device is subscribed yet — turn alerts on first.';

            return;
        }

        $reports = app(WebPushChannel::class)->send($user, new PushTest);
        $failure = collect($reports)->first(fn ($report) => ! $report->isSuccess());

        if ($failure === null) {
            $this->testFailed = false;
            $this->testResult = 'Sent — it should arrive in a moment.';

            return;
        }

        $this->testResult = $failure->isSubscriptionExpired()
            ? 'This device’s subscription expired — turn alerts off and on again.'
            : 'The push service didn’t accept it ('.Str::limit($failure->getReason(), 100).').';
    }
};
?>

@assets
<script>
    (() => {
        const register = () => Alpine.data('pushAlerts', (publicKey) => ({
            supported: false,
            iosNeedsInstall: false,
            denied: false,
            subscribed: false,
            busy: false,
            error: '',

            async init() {
                const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
                const isInstalled = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;

                this.iosNeedsInstall = isIos && ! isInstalled;
                this.supported = window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

                if (! this.supported) {
                    return;
                }

                this.denied = Notification.permission === 'denied';

                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();

                    this.subscribed = subscription !== null && Notification.permission === 'granted';

                    // Re-save once per page load so a subscription the server
                    // dropped (or never got) heals itself.
                    if (this.subscribed && ! window.eosPushSynced) {
                        window.eosPushSynced = true;
                        await this.save(subscription);
                    }
                } catch (error) {
                    // No service worker yet — the button stays available.
                }
            },

            async enable() {
                this.busy = true;
                this.error = '';

                try {
                    const permission = await Notification.requestPermission();

                    this.denied = permission === 'denied';

                    if (permission !== 'granted') {
                        return;
                    }

                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription()
                        || await registration.pushManager.subscribe({
                            userVisibleOnly: true,
                            applicationServerKey: this.toBytes(publicKey),
                        });

                    await this.save(subscription);
                    this.subscribed = true;
                } catch (error) {
                    this.error = 'Your browser couldn’t reach its push service. Some networks block it — try another connection.';
                } finally {
                    this.busy = false;
                }
            },

            async disable() {
                this.busy = true;
                this.error = '';

                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();

                    if (subscription) {
                        await this.$wire.removePushSubscription(subscription.endpoint);
                        await subscription.unsubscribe();
                    }

                    this.subscribed = false;
                } catch (error) {
                    this.error = 'Couldn’t turn alerts off. Try again.';
                } finally {
                    this.busy = false;
                }
            },

            save(subscription) {
                const json = subscription.toJSON();
                const encoding = (window.PushManager.supportedContentEncodings || ['aes128gcm'])[0];

                return this.$wire.savePushSubscription(json.endpoint, json.keys.p256dh, json.keys.auth, encoding);
            },

            toBytes(base64Url) {
                const padded = (base64Url + '='.repeat((4 - base64Url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
                const raw = atob(padded);

                return Uint8Array.from(raw, (character) => character.charCodeAt(0));
            },
        }));

        window.Alpine ? register() : document.addEventListener('alpine:init', register);
    })();
</script>
@endassets

<div>
    @if (config('webpush.vapid.public_key'))
        <div
            x-data="pushAlerts(@js(config('webpush.vapid.public_key')))"
            class="border-t border-line px-3 py-2.5 text-xs dark:border-line-dark"
        >
            <p x-show="! supported" x-cloak class="text-ink-faint dark:text-ink-faint-dark">
                <span x-show="iosNeedsInstall">On iPhone, add English OS to your Home Screen and open it from there to get phone alerts.</span>
                <span x-show="! iosNeedsInstall">Phone alerts aren’t available in this browser.</span>
            </p>

            <p x-show="supported && denied" x-cloak class="text-ink-faint dark:text-ink-faint-dark">Notifications are blocked for English OS — allow them in your browser or phone settings to get phone alerts.</p>

            <button
                type="button"
                x-show="supported && ! denied && ! subscribed"
                x-cloak
                x-on:click="enable()"
                :disabled="busy"
                class="flex w-full cursor-pointer items-center gap-2 font-semibold text-accent-ink transition-colors hover:underline disabled:pointer-events-none disabled:opacity-50 dark:text-accent-ink-dark"
            >
                @svg('heroicon-o-device-phone-mobile', 'h-4 w-4')
                <span x-text="busy ? 'Turning on…' : 'Get alerts on this phone'"></span>
            </button>

            <div x-show="supported && subscribed" x-cloak class="flex items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-device-phone-mobile', 'h-4 w-4') Phone alerts on
                </span>
                <span class="flex items-center gap-3">
                    <button
                        type="button"
                        wire:click="sendTest"
                        wire:loading.attr="disabled"
                        wire:target="sendTest"
                        class="cursor-pointer font-semibold text-accent-ink transition-colors hover:underline disabled:pointer-events-none disabled:opacity-50 dark:text-accent-ink-dark"
                    >
                        <span wire:loading.remove wire:target="sendTest">Send a test</span>
                        <span wire:loading wire:target="sendTest">Sending…</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="disable()"
                        :disabled="busy"
                        class="cursor-pointer font-semibold text-ink-faint transition-colors hover:underline disabled:pointer-events-none disabled:opacity-50 dark:text-ink-faint-dark"
                    >Turn off</button>
                </span>
            </div>

            <p x-show="error" x-cloak x-text="error" class="mt-1.5 text-danger-ink"></p>

            @if ($testResult !== '')
                <p @class([
                    'mt-1.5',
                    'text-danger-ink' => $testFailed,
                    'text-ink-faint dark:text-ink-faint-dark' => ! $testFailed,
                ])>{{ $testResult }}</p>
            @endif
        </div>
    @endif
</div>
