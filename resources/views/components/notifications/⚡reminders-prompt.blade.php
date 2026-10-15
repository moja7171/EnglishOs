<?php

use Livewire\Component;

new class extends Component
{
    /**
     * 'sheet' is the first-time invitation (a bottom sheet on a phone, a
     * dialog on a wide screen) mounted once in the layout; 'card' is the
     * quiet card on the home page that stays until the learner acts.
     */
    public string $variant = 'card';

    public bool $hasStudyTimes = false;

    public function mount(string $variant = 'card'): void
    {
        $this->variant = $variant;
        $this->hasStudyTimes = auth()->user()->hasStudyReminderTimes();
    }

    /**
     * The one-tap setup: noon and 8 pm. Never overwrites times the learner
     * already chose — it is only offered while both slots are empty.
     */
    public function useSuggestedTimes(): void
    {
        $user = auth()->user();

        if (! $user->hasStudyReminderTimes()) {
            $user->rememberTimezone();
            $user->forceFill($user->studyReminderAttributes(1, '12:00') + $user->studyReminderAttributes(2, '20:00'))->save();
        }

        $this->hasStudyTimes = true;
    }
};
?>

@assets
<script>
    (() => {
        const DAY = 24 * 60 * 60 * 1000;

        const read = (key) => {
            try { return Number(localStorage.getItem(key)) || 0; } catch (error) { return 0; }
        };

        const write = (key, value) => {
            try { localStorage.setItem(key, value); } catch (error) {}
        };

        const register = () => Alpine.data('remindersPrompt', (variant) => ({
            push: { ready: false, supported: false, iosNeedsInstall: false, denied: false, subscribed: false, busy: false, error: '' },
            sheetOpen: false,
            cardDismissed: false,
            timesDismissed: false,

            init() {
                this.cardDismissed = read('eosAlertsCardDismissedUntil') > Date.now();
                this.timesDismissed = read('eosTimesCardDismissedUntil') > Date.now();

                if (window.eosPush) {
                    this.update(window.eosPush);
                }

                window.addEventListener('eos-push-state', (event) => this.update(event.detail));
            },

            update(state) {
                this.push = { ...state };

                // The first-time invitation: a moment after the page settles,
                // and only where turning alerts on can work.
                if (variant === 'sheet' && ! this.sheetOpen && this.canInvite && ! this.push.denied && ! read('eosAlertsSheetSeen')) {
                    setTimeout(() => {
                        if (this.canInvite) {
                            this.sheetOpen = true;
                            // Shown once, ever: ignoring it leaves the home-page card.
                            write('eosAlertsSheetSeen', '1');
                        }
                    }, 1200);
                }
            },

            // Alerts can be offered when the browser supports them, or on an
            // iPhone that first needs the app added to the Home Screen.
            get canInvite() {
                return this.push.ready && ! this.push.subscribed && (this.push.supported || this.push.iosNeedsInstall);
            },

            get showEnableCard() {
                return variant === 'card' && ! this.cardDismissed && this.canInvite;
            },

            get showTimesCard() {
                return variant === 'card' && ! this.timesDismissed && this.push.ready && this.push.subscribed && ! this.$wire.hasStudyTimes;
            },

            enable() {
                window.dispatchEvent(new Event('eos-push-enable'));
            },

            closeSheet() {
                this.sheetOpen = false;
                write('eosAlertsSheetSeen', '1');
            },

            dismissEnableCard() {
                this.cardDismissed = true;
                write('eosAlertsCardDismissedUntil', Date.now() + 3 * DAY);
            },

            dismissTimesCard() {
                this.timesDismissed = true;
                write('eosTimesCardDismissedUntil', Date.now() + 7 * DAY);
            },
        }));

        window.Alpine ? register() : document.addEventListener('alpine:init', register);
    })();
</script>
@endassets

<div>
    @if (config('webpush.vapid.public_key'))
        <div x-data="remindersPrompt(@js($variant))" dir="rtl">
            @if ($variant === 'sheet')
                <div
                    x-show="sheetOpen"
                    x-cloak
                    x-on:click="closeSheet()"
                    x-transition.opacity.duration.200ms
                    aria-hidden="true"
                    class="fixed inset-0 z-40 bg-ink/40"
                ></div>

                <div
                    x-show="sheetOpen"
                    x-cloak
                    x-on:keydown.escape.window="closeSheet()"
                    x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0"
                    x-transition:enter-start="translate-y-full sm:translate-y-4 sm:opacity-0"
                    x-transition:enter-end="translate-y-0 sm:opacity-100"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="reminders-sheet-title"
                    class="fixed inset-x-0 bottom-0 z-50 max-h-[90dvh] overflow-y-auto overscroll-contain rounded-t-2xl border border-b-0 border-line bg-surface p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:inset-x-auto sm:bottom-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl sm:border-b dark:border-line-dark dark:bg-surface-dark"
                >
                    {{-- Not subscribed yet: the invitation. --}}
                    <div x-show="! push.subscribed" class="space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                @svg('heroicon-o-bell-alert', 'h-6 w-6')
                            </span>
                            <div>
                                <h2 id="reminders-sheet-title" class="font-display text-lg font-extrabold text-ink dark:text-ink-dark">یادآورها را روشن کنید</h2>
                                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">تا انگلیسی را رها نکنید، به‌موقع برایتان پیام می‌فرستیم:</p>
                            </div>
                        </div>

                        <ul class="space-y-2 text-sm text-ink dark:text-ink-dark">
                            <li class="flex gap-2">@svg('heroicon-s-clock', 'mt-0.5 h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark')<span><b>ساعت مطالعه</b> — تا دو بار در روز، هر ساعتی که خودتان بخواهید</span></li>
                            <li class="flex gap-2">@svg('heroicon-s-arrow-path', 'mt-0.5 h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark')<span><b>مرور کلمات</b> — وقتی چیزی برای مرور دارید</span></li>
                            <li class="flex gap-2">@svg('heroicon-s-fire', 'mt-0.5 h-4 w-4 shrink-0 text-accent-ink dark:text-accent-ink-dark')<span><b>حفظ زنجیره</b> — پیش از آنکه زنجیرهٔ یادگیری‌تان بشکند</span></li>
                        </ul>

                        <p x-show="push.iosNeedsInstall" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                            در iPhone ابتدا برنامه را به صفحهٔ اصلی اضافه کنید: دکمهٔ <b>Share</b> را بزنید، <b>Add to Home Screen</b> را انتخاب کنید و برنامه را از آیکون جدید باز کنید. بعد دوباره همین‌جا یادآورها را روشن کنید.
                        </p>

                        <p x-show="push.denied" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                            اعلان‌ها برای English OS مسدود است. در تنظیمات مرورگر یا گوشی اجازه‌شان بدهید و دوباره امتحان کنید.
                        </p>

                        <p x-show="push.error" x-cloak x-text="push.error" class="text-xs text-danger-ink"></p>

                        <div class="flex flex-col gap-2 sm:flex-row-reverse">
                            <button
                                type="button"
                                x-show="! push.iosNeedsInstall && ! push.denied"
                                x-on:click="enable()"
                                :disabled="push.busy"
                                class="flex-1 cursor-pointer rounded-full bg-accent px-5 py-3 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                                x-text="push.busy ? 'در حال روشن کردن…' : 'روشن کردن یادآورها'"
                            ></button>
                            <button
                                type="button"
                                x-on:click="closeSheet()"
                                class="cursor-pointer rounded-full border border-line px-5 py-3 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >بعداً</button>
                        </div>
                    </div>

                    {{-- Subscribed: confirm, then invite them to pick study times. --}}
                    <div x-show="push.subscribed" x-cloak class="space-y-4">
                        <div class="flex items-start gap-3">
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                                @svg('heroicon-o-check-circle', 'h-6 w-6')
                            </span>
                            <div>
                                <h2 class="font-display text-lg font-extrabold text-ink dark:text-ink-dark">یادآورها روشن شد</h2>
                                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">یادآور مرور و حفظ زنجیره خودکار کار می‌کنند.</p>
                            </div>
                        </div>

                        <div x-show="! $wire.hasStudyTimes" class="space-y-3 rounded-xl bg-surface-sunken p-3 dark:bg-surface-sunken-dark">
                            <p class="text-sm text-ink dark:text-ink-dark">می‌خواهید ساعت مطالعه هم بگذارید؟ تا دو بار در روز یادتان می‌اندازیم.</p>
                            <div class="flex flex-col gap-2 sm:flex-row-reverse">
                                <button
                                    type="button"
                                    wire:click="useSuggestedTimes"
                                    wire:loading.attr="disabled"
                                    class="flex-1 cursor-pointer rounded-full bg-accent px-4 py-2.5 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                                >ساعت ۱۲:۰۰ و ۲۰:۰۰ را روشن کن</button>
                                <a
                                    href="{{ route('profile', ['tab' => 'reminders']) }}"
                                    wire:navigate
                                    x-on:click="closeSheet()"
                                    class="rounded-full border border-line px-4 py-2.5 text-center text-sm font-semibold text-ink-soft transition-colors hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                                >انتخاب ساعت دلخواه</a>
                            </div>
                        </div>

                        <p x-show="$wire.hasStudyTimes" x-cloak class="text-sm text-ink-soft dark:text-ink-soft-dark">ساعت‌های مطالعه تنظیم شد. هر وقت خواستید از تب «Reminders» در پروفایل عوضشان کنید.</p>

                        <button
                            type="button"
                            x-on:click="closeSheet()"
                            class="w-full cursor-pointer rounded-full border border-line px-5 py-2.5 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >بستن</button>
                    </div>
                </div>
            @else
                {{-- Alerts are off: keep inviting, but quietly. --}}
                <div x-show="showEnableCard" x-cloak x-transition class="card space-y-3 p-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                            @svg('heroicon-o-bell-alert', 'h-5 w-5')
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">یادآورهای گوشی خاموش است</p>
                            <p class="mt-0.5 text-xs text-ink-soft dark:text-ink-soft-dark">روشنشان کنید تا ساعت مطالعه، مرور کلمات و زنجیرهٔ یادگیری‌تان از یادتان نرود.</p>
                        </div>
                        <button type="button" x-on:click="dismissEnableCard()" aria-label="بستن" class="cursor-pointer text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                            @svg('heroicon-o-x-mark', 'h-4 w-4')
                        </button>
                    </div>

                    <p x-show="push.iosNeedsInstall" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                        در iPhone ابتدا برنامه را به صفحهٔ اصلی اضافه کنید: دکمهٔ <b>Share</b> ← <b>Add to Home Screen</b>، و برنامه را از آیکون جدید باز کنید.
                    </p>
                    <p x-show="push.denied" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                        اعلان‌ها برای English OS مسدود است. در تنظیمات مرورگر یا گوشی اجازه‌شان بدهید.
                    </p>
                    <p x-show="push.error" x-cloak x-text="push.error" class="text-xs text-danger-ink"></p>

                    <button
                        type="button"
                        x-show="! push.iosNeedsInstall && ! push.denied"
                        x-on:click="enable()"
                        :disabled="push.busy"
                        class="w-full cursor-pointer rounded-full bg-accent px-4 py-2.5 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                        x-text="push.busy ? 'در حال روشن کردن…' : 'روشن کردن یادآورها'"
                    ></button>
                </div>

                {{-- Alerts are on but no study time yet. --}}
                <div x-show="showTimesCard" x-cloak x-transition class="card space-y-3 p-4">
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                            @svg('heroicon-o-clock', 'h-5 w-5')
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">ساعت مطالعه‌تان را تنظیم کنید</p>
                            <p class="mt-0.5 text-xs text-ink-soft dark:text-ink-soft-dark">تا دو بار در روز، سر ساعتی که خودتان می‌گذارید، یادآوری می‌گیرید.</p>
                        </div>
                        <button type="button" x-on:click="dismissTimesCard()" aria-label="بستن" class="cursor-pointer text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                            @svg('heroicon-o-x-mark', 'h-4 w-4')
                        </button>
                    </div>

                    <div class="flex flex-col gap-2 sm:flex-row-reverse">
                        <button
                            type="button"
                            wire:click="useSuggestedTimes"
                            wire:loading.attr="disabled"
                            class="flex-1 cursor-pointer rounded-full bg-accent px-4 py-2.5 text-sm font-bold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                        >ساعت ۱۲:۰۰ و ۲۰:۰۰ را روشن کن</button>
                        <a
                            href="{{ route('profile', ['tab' => 'reminders']) }}"
                            wire:navigate
                            class="rounded-full border border-line px-4 py-2.5 text-center text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >انتخاب ساعت دلخواه</a>
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
