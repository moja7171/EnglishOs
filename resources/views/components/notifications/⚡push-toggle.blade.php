<?php

use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use NotificationChannels\WebPush\PushSubscription;

new class extends Component
{
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
};
?>

{{--
    The one place that talks to the browser's push service. Its state is
    published on `window.eosPush` (and an `eos-push-state` event) so the
    first-time sheet and the home-page cards in
    <livewire:notifications.reminders-prompt> can show the right invitation,
    and it turns alerts on when they fire `eos-push-enable`.
--}}
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
                    this.publish();

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

                this.publish();
            },

            publish() {
                window.eosPush = {
                    ready: true,
                    supported: this.supported,
                    iosNeedsInstall: this.iosNeedsInstall,
                    denied: this.denied,
                    subscribed: this.subscribed,
                    busy: this.busy,
                    error: this.error,
                };

                window.dispatchEvent(new CustomEvent('eos-push-state', { detail: window.eosPush }));
            },

            async enable() {
                if (this.busy || this.subscribed) {
                    return;
                }

                this.busy = true;
                this.error = '';
                this.publish();

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
                    this.error = 'مرورگر شما به سرویس اعلان دسترسی پیدا نکرد. بعضی شبکه‌ها آن را مسدود می‌کنند؛ شبکه یا VPN را عوض کنید و دوباره امتحان کنید.';
                } finally {
                    this.busy = false;
                    this.publish();
                }
            },

            async disable() {
                this.busy = true;
                this.error = '';
                this.publish();

                try {
                    const registration = await navigator.serviceWorker.ready;
                    const subscription = await registration.pushManager.getSubscription();

                    if (subscription) {
                        await this.$wire.removePushSubscription(subscription.endpoint);
                        await subscription.unsubscribe();
                    }

                    this.subscribed = false;
                } catch (error) {
                    this.error = 'خاموش کردن انجام نشد. دوباره امتحان کنید.';
                } finally {
                    this.busy = false;
                    this.publish();
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
            x-on:eos-push-enable.window="enable()"
            dir="rtl"
            class="border-t border-line px-3 py-2.5 text-xs dark:border-line-dark"
        >
            <p x-show="! supported" x-cloak class="text-ink-faint dark:text-ink-faint-dark">
                <span x-show="iosNeedsInstall">در iPhone ابتدا برنامه را به صفحهٔ اصلی اضافه کنید (دکمهٔ Share ← Add to Home Screen) و از همان‌جا باز کنید تا یادآورها کار کنند.</span>
                <span x-show="! iosNeedsInstall">یادآور روی گوشی در این مرورگر پشتیبانی نمی‌شود.</span>
            </p>

            <p x-show="supported && denied" x-cloak class="text-ink-faint dark:text-ink-faint-dark">اعلان‌ها برای English OS مسدود است؛ در تنظیمات مرورگر یا گوشی اجازه‌شان بدهید.</p>

            <button
                type="button"
                x-show="supported && ! denied && ! subscribed"
                x-cloak
                x-on:click="enable()"
                :disabled="busy"
                class="flex w-full cursor-pointer items-center gap-2 font-semibold text-accent-ink transition-colors hover:underline disabled:pointer-events-none disabled:opacity-50 dark:text-accent-ink-dark"
            >
                @svg('heroicon-o-device-phone-mobile', 'h-4 w-4')
                <span x-text="busy ? 'در حال روشن کردن…' : 'روشن کردن یادآورها روی گوشی'"></span>
            </button>

            <div x-show="supported && subscribed" x-cloak class="flex items-center justify-between gap-2">
                <span class="inline-flex items-center gap-1.5 font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-device-phone-mobile', 'h-4 w-4') یادآورهای گوشی روشن است
                </span>
                <span class="flex items-center gap-3">
                    <a
                        href="{{ route('profile', ['tab' => 'reminders']) }}"
                        wire:navigate
                        x-on:click="open = false"
                        class="font-semibold text-accent-ink transition-colors hover:underline dark:text-accent-ink-dark"
                    >تنظیم ساعت‌ها</a>
                    <button
                        type="button"
                        x-on:click="disable()"
                        :disabled="busy"
                        class="cursor-pointer font-semibold text-ink-faint transition-colors hover:underline disabled:pointer-events-none disabled:opacity-50 dark:text-ink-faint-dark"
                    >خاموش کردن</button>
                </span>
            </div>

            <p x-show="error" x-cloak x-text="error" class="mt-1.5 text-danger-ink"></p>
        </div>
    @endif
</div>
