{{-- The Guide: the three swipe cards (<x-guide-cards>), then only short
     questions, each folded to a one- or two-line answer. --}}
<x-layouts.app>
    <div class="mx-auto max-w-2xl space-y-4 p-4 sm:p-6">
        <header class="flex items-center gap-2">
            <a href="{{ route('profile') }}" wire:navigate aria-label="Back" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Guide</h1>
        </header>

        <x-guide-cards />

        <section dir="rtl" class="font-fa space-y-2">
            <h2 class="px-1 text-sm font-extrabold text-ink-faint dark:text-ink-faint-dark">سؤال‌ها</h2>
            <div class="card divide-y divide-line rounded-2xl px-4 py-0 dark:divide-line-dark">
                @foreach ([
                    ['اگه یه روز جا موندم چی می‌شه؟', 'چیزی از دست نمی‌ره؛ مأموریت منتظرت می‌مونه، فقط استریک صفر می‌شه.', null],
                    ['میکروفون لازم دارم؟', 'برای تمرین‌های گفتاری بله؛ میکروفون گوشی کافیه.', null],
                    ['می‌تونم یه مرحله رو دوباره انجام بدم؟', 'بله؛ از صفحه‌ی مأموریت، روزش رو باز کن.', null],
                    ['بیرون از اپ چی گوش بدم؟', 'روزی یه فایل که ۷۰ تا ۸۰ درصدش رو بفهمی، بدون زیرنویس فارسی؛ مثلاً BBC Learning English یا Rachel\'s English در یوتیوب.', ['listening.show', 'پیشنهادهای امروز']],
                    ['چرا این‌همه تکرار؟', 'چون چیزی که با فاصله دوباره می‌بینی، تو حافظه‌ی بلندمدت می‌مونه؛ مرور روزانه همین کار رو می‌کنه.', null],
                    ['نتیجه‌ی مأموریت چطور تعیین می‌شه؟', 'AI از چیزهایی که واقعاً گفتی و نوشتی تصمیم می‌گیره؛ اگه لازم باشه یه مرحله رو دوباره پیشنهاد می‌ده.', null],
                ] as [$question, $answer, $link])
                    <div wire:key="faq-{{ $loop->index }}" x-data="{ open: false }">
                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex w-full cursor-pointer items-center justify-between gap-3 py-3.5 text-start text-sm font-bold text-ink dark:text-ink-dark">
                            <span>{{ $question }}</span>
                            <span class="shrink-0 text-ink-faint transition-transform dark:text-ink-faint-dark" x-bind:class="open && 'rotate-180'">@svg('heroicon-o-chevron-down', 'h-4 w-4')</span>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.150ms class="pb-3.5 text-sm leading-7 text-ink-soft dark:text-ink-soft-dark">
                            {{ $answer }}
                            @if ($link)
                                <a href="{{ route($link[0]) }}" wire:navigate class="font-bold text-accent-ink dark:text-accent-ink-dark">{{ $link[1] }} ›</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts.app>
