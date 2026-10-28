<?php

use App\Models\DayActivity;
use Livewire\Component;

new class extends Component
{
    /**
     * 'sheet' is the first-time invitation (a bottom sheet on a phone, a
     * dialog on a wide screen), shown once after the first lesson; 'card' is
     * the slim row on the home page that stays until the learner acts.
     */
    public string $variant = 'card';

    public bool $hasStudyTimes = false;

    /** The sheet waits for the first real lesson activity. */
    public bool $hasStarted = false;

    public function mount(string $variant = 'card'): void
    {
        $this->variant = $variant;
        $this->hasStudyTimes = auth()->user()->hasStudyReminderTimes();
        $this->hasStarted = DayActivity::where('learner_id', auth()->id())->exists();

        // Scheduled alerts run with no browser, so they read the zone saved
        // here — kept fresh every time the learner opens the app.
        auth()->user()->rememberTimezone();
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

        const register = () => Alpine.data('remindersPrompt', (variant, hasStarted) => ({
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
                if (variant === 'sheet' && hasStarted && ! this.sheetOpen && this.canInvite && ! this.push.denied && ! read('eosAlertsSheetSeen')) {
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
        <div x-data="remindersPrompt(@js($variant), @js($hasStarted))" dir="rtl">
            @if ($variant === 'sheet')
                @if ($hasStarted)
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
                        class="fixed inset-x-0 bottom-0 z-50 max-h-[90dvh] overflow-y-auto overscroll-contain rounded-t-3xl border border-b-0 border-line bg-surface p-6 pb-[calc(1.5rem+env(safe-area-inset-bottom))] shadow-2xl sm:inset-x-auto sm:bottom-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-3xl sm:border-b dark:border-line-dark dark:bg-surface-dark"
                    >
                        {{-- Not subscribed yet: the invitation. --}}
                        <div x-show="! push.subscribed" class="flex flex-col items-center gap-3 text-center">
                            <span class="inline-flex h-[4.5rem] w-[4.5rem] items-center justify-center rounded-3xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                                @svg('heroicon-o-bell-alert', 'h-9 w-9')
                            </span>
                            <h2 id="reminders-sheet-title" class="text-xl font-extrabold text-ink dark:text-ink-dark">فردا یادتان می‌اندازیم</h2>
                            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">وقتی وقت درس یا مرور است، روی گوشی پیام می‌گیرید.</p>
                            <p x-show="push.iosNeedsInstall" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                                در iPhone ابتدا برنامه را به صفحهٔ اصلی اضافه کنید: <b>Share</b> ← <b>Add to Home Screen</b>، بعد از آیکون جدید باز کنید.
                            </p>
                            <p x-show="push.denied" x-cloak class="rounded-xl bg-surface-sunken p-3 text-xs text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
                                اعلان‌ها برای English OS مسدود است؛ در تنظیمات مرورگر یا گوشی اجازه‌شان بدهید.
                            </p>
                            <p x-show="push.error" x-cloak x-text="push.error" class="text-xs text-danger-ink"></p>
                            <button
                                type="button"
                                x-show="! push.iosNeedsInstall && ! push.denied"
                                x-on:click="enable()"
                                :disabled="push.busy"
                                class="mt-1 w-full cursor-pointer rounded-full bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,#000)] transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                                x-text="push.busy ? 'در حال روشن کردن…' : 'روشن کن'"
                            ></button>
                            <button type="button" x-on:click="closeSheet()" class="cursor-pointer text-sm font-bold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">بعداً</button>
                        </div>
                        {{-- Subscribed: confirm, then offer study times. --}}
                        <div x-show="push.subscribed" x-cloak class="flex flex-col items-center gap-3 text-center">
                            <span class="inline-flex h-[4.5rem] w-[4.5rem] items-center justify-center rounded-3xl bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark">
                                @svg('heroicon-o-check-circle', 'h-9 w-9')
                            </span>
                            <h2 class="text-xl font-extrabold text-ink dark:text-ink-dark">یادآورها روشن شد</h2>
                            <div x-show="! $wire.hasStudyTimes" class="flex w-full flex-col gap-2">
                                <p class="text-sm text-ink-soft dark:text-ink-soft-dark">ساعت مطالعه هم بگذاریم؟</p>
                                <button
                                    type="button"
                                    wire:click="useSuggestedTimes"
                                    wire:loading.attr="disabled"
                                    class="w-full cursor-pointer rounded-full bg-accent px-5 py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,#000)] transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                                >۱۲:۰۰ و ۲۰:۰۰</button>
                                <a
                                    href="{{ route('profile', ['tab' => 'reminders']) }}"
                                    wire:navigate
                                    x-on:click="closeSheet()"
                                    class="text-sm font-bold text-accent-ink dark:text-accent-ink-dark"
                                >ساعت دلخواه</a>
                            </div>
                            <p x-show="$wire.hasStudyTimes" x-cloak class="text-sm text-ink-soft dark:text-ink-soft-dark">ساعت‌ها در Profile › Reminders قابل تغییر است.</p>
                            <button type="button" x-on:click="closeSheet()" class="cursor-pointer text-sm font-bold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">بستن</button>
                        </div>
                    </div>
                @endif
            @else
                {{-- Alerts are off: keep inviting, but quietly. --}}
                <div x-show="showEnableCard" x-cloak x-transition class="card space-y-2 p-2.5 ps-3">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                            @svg('heroicon-o-bell-alert', 'h-4 w-4')
                        </span>
                        <p class="min-w-0 flex-1 text-sm font-bold text-ink dark:text-ink-dark">یادآورهای گوشی خاموش است</p>
                        <button
                            type="button"
                            x-show="! push.iosNeedsInstall && ! push.denied"
                            x-on:click="enable()"
                            :disabled="push.busy"
                            class="shrink-0 cursor-pointer rounded-full bg-accent px-3.5 py-1.5 text-xs font-extrabold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                            x-text="push.busy ? '…' : 'روشن کن'"
                        ></button>
                        <button type="button" x-on:click="dismissEnableCard()" aria-label="بستن" class="cursor-pointer text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                            @svg('heroicon-o-x-mark', 'h-4 w-4')
                        </button>
                    </div>
                    <p x-show="push.iosNeedsInstall" x-cloak class="text-xs text-ink-soft dark:text-ink-soft-dark">
                        در iPhone ابتدا <b>Share</b> ← <b>Add to Home Screen</b> را بزنید و از آیکون جدید باز کنید.
                    </p>
                    <p x-show="push.denied" x-cloak class="text-xs text-ink-soft dark:text-ink-soft-dark">اعلان‌ها مسدود است؛ در تنظیمات مرورگر یا گوشی اجازه‌شان بدهید.</p>
                    <p x-show="push.error" x-cloak x-text="push.error" class="text-xs text-danger-ink"></p>
                </div>
                {{-- Alerts are on but no study time yet. --}}
                <div x-show="showTimesCard" x-cloak x-transition class="card flex items-center gap-3 p-2.5 ps-3">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        @svg('heroicon-o-clock', 'h-4 w-4')
                    </span>
                    <p class="min-w-0 flex-1 text-sm font-bold text-ink dark:text-ink-dark">ساعت مطالعه بگذارید</p>
                    <button
                        type="button"
                        wire:click="useSuggestedTimes"
                        wire:loading.attr="disabled"
                        class="shrink-0 cursor-pointer rounded-full bg-accent px-3.5 py-1.5 text-xs font-extrabold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >۱۲:۰۰ و ۲۰:۰۰</button>
                    <button type="button" x-on:click="dismissTimesCard()" aria-label="بستن" class="cursor-pointer text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                        @svg('heroicon-o-x-mark', 'h-4 w-4')
                    </button>
                </div>
            @endif
        </div>
    @endif
</div>
