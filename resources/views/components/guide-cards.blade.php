{{--
    The three swipe cards that explain the app — how it works, your day in
    four steps, three habits — with dots under them. The Guide shows them
    above its questions; right after sign-up they run full screen once
    (see A15), where $doneUrl adds a Next / Let's go button.

    @param string|null $doneUrl Where the last card's button goes; null = no button.
--}}
@props(['doneUrl' => null])

@php
    $cards = [
        ['bg' => 'from-[#ff8f6b] via-[#c0527a] to-[#3a3466]', 'kicker' => '۱ از ۳', 'title' => 'اپ چطور کار می‌کنه'],
        ['bg' => 'from-[#4cceb0] via-[#1f7a8c] to-[#3a3466]', 'kicker' => '۲ از ۳', 'title' => 'روز تو در ۴ قدم'],
        ['bg' => 'from-[#8b7ce0] via-[#5b4fa8] to-[#3a3466]', 'kicker' => '۳ از ۳', 'title' => '۳ عادت که جواب می‌ده'],
    ];
    $tile = 'rounded-2xl bg-white/15 px-3 py-2.5 text-sm leading-6';
@endphp

<div
    {{ $attributes->class('space-y-3') }}
    x-data="{
        card: 0,
        {{-- The track is right-to-left, so its scrollLeft runs negative. --}}
        go(index) { this.$refs.track.scrollTo({ left: -this.$refs.track.clientWidth * index, behavior: 'smooth' }) },
        track() { this.card = Math.round(Math.abs(this.$refs.track.scrollLeft) / this.$refs.track.clientWidth) },
    }"
>
    <div x-ref="track" x-on:scroll.debounce.50ms="track()" dir="rtl" class="font-fa flex snap-x snap-mandatory gap-3 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        @foreach ($cards as $index => $card)
            <section wire:key="guide-card-{{ $index }}" class="flex min-h-60 w-full shrink-0 snap-center flex-col gap-3 rounded-3xl bg-linear-160 {{ $card['bg'] }} p-5 text-white">
                <p class="text-xs font-extrabold opacity-85">{{ $card['kicker'] }}</p>
                <h2 class="text-xl font-extrabold">{{ $card['title'] }}</h2>

                @if ($index === 0)
                    <p class="text-base leading-9 font-semibold">۲۴ مأموریت · هر کدوم ۴ روز<br>هر روز حدود ۱ ساعت<br>AI حرف و نوشته‌ت رو بررسی می‌کنه</p>
                @elseif ($index === 1)
                    <div class="grid grid-cols-2 gap-2">
                        <span class="{{ $tile }}"><b class="block">⚡ Review</b>۲ دقیقه، اول کار</span>
                        <span class="{{ $tile }}"><b class="block">📖 Lesson</b>۵ مرحله‌ی امروز</span>
                        <span class="{{ $tile }}"><b class="block">🎧 Listening</b>یکی از ۳ فایل</span>
                        <span class="{{ $tile }}"><b class="block">🎙 Daily talk</b>۱۰ دقیقه با Pi</span>
                    </div>
                @else
                    <div class="space-y-2 font-bold">
                        <p class="{{ $tile }}">⏰ هر روز یک ساعت ثابت</p>
                        <p class="{{ $tile }}">🗣 همیشه بلند بگو</p>
                        <p class="{{ $tile }}">⚡ اول مرور، بعد درس</p>
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    <div dir="rtl" class="flex justify-center gap-1.5" aria-hidden="true">
        @foreach ($cards as $index => $card)
            <button type="button" tabindex="-1" x-on:click="go({{ $index }})" class="h-2 cursor-pointer rounded-full transition-all" x-bind:class="card === {{ $index }} ? 'w-5 bg-accent dark:bg-accent-dark' : 'w-2 bg-line dark:bg-line-dark'"></button>
        @endforeach
    </div>

    @if ($doneUrl)
        <div class="pt-2">
            <button type="button" x-show="card < {{ count($cards) - 1 }}" x-on:click="go(card + 1)" class="block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Next</button>
            <a href="{{ $doneUrl }}" wire:navigate x-show="card === {{ count($cards) - 1 }}" x-cloak class="block w-full rounded-2xl bg-accent py-3.5 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Let's go</a>
        </div>
    @endif
</div>
