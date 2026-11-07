@props(['friend', 'pages'])

{{-- A friend's day as a story, like Instagram's: three pages (today, this
     week, overall), each about six seconds, a tap on the right goes on, on
     the left goes back, holding stops the clock. All of it is Persian and
     says exactly where the friend is; counts, names and dates only. The
     look stays dark whatever the app's theme, as stories do. --}}
@php
    $today = $pages['today'];
    $week = $pages['week'];
    $overall = $pages['overall'];
    $position = $overall['position'];
    $circle = 2 * M_PI * 62;
    $panel = 'rounded-2xl bg-white/12 px-4 py-3';
@endphp
<div
    wire:key="story-{{ $friend->id }}"
    x-data="{
        page: 0,
        progress: 0,
        held: false,
        frame: null,
        last: 0,
        seconds: 6,
        init() {
            document.documentElement.classList.add('overflow-hidden');
            this.last = performance.now();
            const run = (now) => {
                if (! this.held && ! document.hidden) {
                    this.progress += (now - this.last) / (this.seconds * 1000);
                    if (this.progress >= 1) this.go(this.page + 1);
                }
                this.last = now;
                this.frame = requestAnimationFrame(run);
            };
            this.frame = requestAnimationFrame(run);
        },
        destroy() {
            cancelAnimationFrame(this.frame);
            document.documentElement.classList.remove('overflow-hidden');
        },
        go(page) {
            if (page > 2) return this.$wire.closeStory();
            this.page = Math.max(0, page);
            this.progress = 0;
        },
    }"
    x-on:keydown.escape.window="$wire.closeStory()"
    x-on:keydown.arrow-right.window="go(page + 1)"
    x-on:keydown.arrow-left.window="go(page - 1)"
    x-back-close="{ open: true, close: () => $wire.closeStory() }"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $friend->name }}"
    class="fixed inset-0 z-50 flex flex-col text-white select-none"
>
    <div class="absolute inset-0 -z-10 transition-colors duration-300" x-bind:class="['bg-linear-to-br from-[#211d3f] via-[#3b2f6e] to-[#6a3b6b]', 'bg-linear-to-br from-[#14213d] via-[#1e3a5f] to-[#1f6f78]', 'bg-linear-to-br from-[#2a1b3d] via-[#5b2a5e] to-[#c2543f]'][page]"></div>

    <div class="mx-auto flex min-h-0 w-full max-w-lg flex-1 flex-col pt-[max(0.75rem,env(safe-area-inset-top))]" dir="ltr">
        <div class="flex gap-1 px-3" aria-hidden="true">
            @foreach ([0, 1, 2] as $bar)
                <span class="h-[3px] flex-1 overflow-hidden rounded-full bg-white/30"><span class="block h-full bg-white" x-bind:style="`width: ${page > {{ $bar }} ? 100 : (page === {{ $bar }} ? progress * 100 : 0)}%`"></span></span>
            @endforeach
        </div>

        <div class="flex items-center gap-3 px-3 pt-3 pb-1">
            <a href="{{ route('friends.profile', $friend) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-3">
                <x-friends.day-ring :slices="$pages['ring']">
                    <x-user-avatar :user="$friend" class="h-11 w-11 text-sm" />
                </x-friends.day-ring>
                <span class="min-w-0 flex-1" dir="rtl">
                    <span class="font-fa block truncate text-sm font-extrabold">{{ $pages['self'] ? 'تو' : $friend->name }}</span>
                    <span class="font-fa block truncate text-xs font-semibold text-white/75">{{ $pages['where'] ?? 'الان مأموریتی باز ندارد' }}</span>
                </span>
            </a>
            <button type="button" x-on:click="$wire.closeStory()" aria-label="Close" class="inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full bg-white/15 hover:bg-white/25">@svg('heroicon-o-x-mark', 'h-5 w-5')</button>
        </div>

        {{-- Tap the right two thirds: on; the left third: back; hold: pause. Scrolling a long page doesn't count as a tap. --}}
        <div class="font-fa relative min-h-0 flex-1 overflow-hidden px-5 pt-4 pb-3" dir="rtl" x-on:click="const box = $el.getBoundingClientRect(); go($event.clientX - box.left < box.width / 3 ? page - 1 : page + 1)" x-on:pointerdown="held = true" x-on:pointerup="held = false" x-on:pointerleave="held = false" x-on:pointercancel="held = false">
            {{-- 1 · Today --}}
            <section x-show="page === 0" x-transition.opacity.duration.300ms class="flex h-full flex-col gap-3.5 overflow-y-auto pb-2" data-testid="story-today">
                <p class="text-xs font-extrabold tracking-wide text-white/70">امروز</p>
                <div class="flex items-end gap-3">
                    <span class="font-display text-7xl leading-none font-extrabold tabular-nums">{{ $today['count'] }}</span>
                    <span class="pb-2 text-base font-bold">{{ $today['count'] === 0 ? 'کار تا حالا' : ($pages['self'] ? 'کار انجام دادی' : 'کار انجام داد') }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <p @class(['rounded-full px-3 py-1 text-xs font-extrabold', 'bg-emerald-300/25 text-emerald-200' => $pages['ring'] >= 2, 'bg-white/15 text-white' => $pages['ring'] < 2])>{{ $today['ringNote'] }}</p>
                    @if ($today['lastAt'])
                        <p class="rounded-full bg-white/15 px-3 py-1 text-xs font-extrabold">آخرین کار: ساعت <bdi dir="ltr">{{ $today['lastAt'] }}</bdi></p>
                    @endif
                </div>
                <div class="flex min-h-0 flex-col gap-2.5 overflow-y-auto pb-2">
                    @forelse ($today['items'] as $item)
                        <div class="{{ $panel }} flex flex-col gap-0.5">
                            <span class="text-sm font-extrabold">{{ $item['title'] }}</span>
                            @if ($item['line'] !== '')
                                <span class="text-xs font-semibold text-white/80">{{ $item['line'] }}</span>
                            @endif
                            @foreach ($item['details'] ?? [] as $detail)
                                <span class="mt-1 flex items-baseline gap-2 text-xs font-semibold text-white/90">
                                    <span class="min-w-0 flex-1"><bdi class="font-bold">{{ $detail['name'] }}</bdi>@if ($detail['note'] !== '')<span class="block text-[11px] text-white/65">{{ $detail['note'] }}</span>@endif</span>
                                    @if ($detail['times'] > 0)
                                        <span class="shrink-0 rounded-full bg-white/15 px-2 py-0.5 font-extrabold tabular-nums">{{ $detail['times'] }} بار</span>
                                    @else
                                        <span class="shrink-0 text-emerald-300" aria-hidden="true">✓</span>
                                    @endif
                                </span>
                            @endforeach
                        </div>
                    @empty
                        <div class="{{ $panel }} flex flex-col gap-0.5">
                            <span class="text-sm font-extrabold">{{ $today['last'] }}</span>
                            @if ($overall['streak'] > 0)
                                <span class="text-xs font-semibold text-white/80">{{ $pages['self'] ? 'زنجیرهٔ '.$overall['streak'].' روزهٔ تمرینت منتظر امروز است' : 'زنجیرهٔ '.$overall['streak'].' روزهٔ تمرینش منتظر امروز است' }}</span>
                            @endif
                        </div>
                    @endforelse
                </div>
            </section>

            {{-- 2 · This week --}}
            <section x-show="page === 1" x-cloak x-transition.opacity.duration.300ms class="flex h-full flex-col gap-3.5 overflow-y-auto pb-2" data-testid="story-week">
                <p class="text-xs font-extrabold tracking-wide text-white/70">این هفته</p>
                <div class="flex items-end gap-3">
                    <span class="font-display text-7xl leading-none font-extrabold tabular-nums">{{ $week['practised'] }}</span>
                    <span class="pb-2 text-base font-bold">روز از 7 روز تمرین {{ $pages['self'] ? 'کردی' : 'کرد' }}</span>
                </div>
                <div class="grid grid-cols-7 gap-1" dir="rtl">
                    @foreach ($week['days'] as $day)
                        <div class="flex flex-col items-center gap-1 text-[11px] font-extrabold">
                            <span class="text-white/75">{{ $day['letter'] }}</span>
                            <span @class([
                                'flex aspect-square w-full max-w-9 items-center justify-center rounded-full text-sm',
                                'bg-[#ff8163] text-white' => $day['state'] === 'mission',
                                'bg-emerald-300 text-emerald-950' => $day['state'] === 'practised',
                                'bg-white/15' => $day['state'] === 'none',
                                'border-2 border-dashed border-white/35' => $day['state'] === 'future',
                                'ring-2 ring-white ring-offset-2 ring-offset-transparent' => $day['today'],
                            ])>{{ $day['state'] === 'mission' || $day['state'] === 'practised' ? '✓' : '' }}</span>
                            <span class="h-3 text-[10px] text-[#ffc4b3]">{{ $day['missionDay'] ? 'روز '.$day['missionDay'] : '' }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs font-semibold text-white/75">نارنجی = روز مأموریت · سبز = تمرین دیگر · شنبه تا جمعه</p>
                <div class="flex min-h-0 flex-col gap-1.5 overflow-y-auto" data-testid="story-week-days">
                    @foreach ($week['days'] as $day)
                        @if ($day['kinds'])
                            <p class="flex items-baseline gap-2 text-xs font-semibold"><span class="w-5 shrink-0 font-extrabold text-white/70">{{ $day['letter'] }}</span><span class="min-w-0 flex-1">{{ implode('، ', $day['kinds']) }}</span></p>
                        @endif
                    @endforeach
                </div>
                @unless ($pages['self'])
                <div class="{{ $panel }} flex flex-col gap-2.5" data-testid="story-compare">
                    @foreach ([[$friend->name, $week['practised'], 'bg-emerald-300'], ['تو', $week['viewerPractised'], 'bg-[#ffb49c]']] as [$who, $count, $color])
                        <div class="grid grid-cols-[3.5rem_1fr_1.5rem] items-center gap-3 text-sm font-extrabold">
                            <span class="truncate">{{ $who }}</span>
                            <span class="h-2.5 overflow-hidden rounded-full bg-white/15"><span class="block h-full rounded-full {{ $color }}" style="width: {{ $count / 7 * 100 }}%"></span></span>
                            <span class="font-display text-base tabular-nums">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
                @endunless
                <p class="text-xs font-semibold text-white/75">{{ $week['amount'] }} کار از شنبه تا حالا@if ($week['totals']) · @foreach ($week['totals'] as $total){{ $total['name'] }} {{ $total['days'] }} روز{{ $loop->last ? '' : '، ' }}@endforeach @endif</p>
            </section>

            {{-- 3 · Overall --}}
            <section x-show="page === 2" x-cloak x-transition.opacity.duration.300ms class="flex h-full flex-col gap-3.5 overflow-y-auto pb-2" data-testid="story-overall">
                <p class="text-xs font-extrabold tracking-wide text-white/70">وضعیت کلی</p>
                <div class="flex items-center gap-4">
                    <span class="relative h-[130px] w-[130px] shrink-0" dir="ltr">
                        <svg viewBox="0 0 150 150" class="h-full w-full -rotate-90" aria-hidden="true">
                            <circle cx="75" cy="75" r="62" fill="none" stroke-width="12" class="stroke-white/20"></circle>
                            <circle cx="75" cy="75" r="62" fill="none" stroke-width="12" stroke-linecap="round" class="stroke-white" stroke-dasharray="{{ round($circle, 1) }}" stroke-dashoffset="{{ round($circle * (1 - $overall['percent'] / 100), 1) }}"></circle>
                        </svg>
                        <span class="absolute inset-0 grid place-items-center text-center"><span><b class="font-display text-4xl font-extrabold">{{ $overall['percent'] }}%</b></span></span>
                    </span>
                    <p class="min-w-0 flex-1 text-sm font-bold">
                        <span class="block text-xs text-white/70">{{ $pages['self'] ? 'الان کجایی' : 'الان کجاست' }}</span>
                        @if ($position)
                            <span class="block text-lg font-extrabold">{{ $position['code'] }} · {{ $position['title'] }}</span>
                            <span class="block">روز {{ $position['day'] }} از {{ $position['dayCount'] }}</span>
                            @if ($position['step'])
                                <span class="block">الان در «{{ $position['step'] }}»</span>
                            @endif
                        @else
                            <span class="block">مأموریتی باز ندارد</span>
                        @endif
                    </p>
                </div>
                @if ($overall['missionDays'])
                    <div class="flex items-center gap-2" aria-hidden="true" data-testid="story-mission-days">
                        @foreach ($overall['missionDays'] as $state)
                            <span @class(['h-2 flex-1 rounded-full', 'bg-emerald-300' => $state === 'done', 'bg-white' => $state === 'current', 'bg-white/20' => $state === 'todo'])></span>
                        @endforeach
                        <span class="text-xs font-extrabold tabular-nums text-white/80">{{ $overall['missionPercent'] }}%</span>
                    </div>
                @endif
                <div class="grid grid-cols-2 gap-2.5">
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['streak'] }}</b><span class="text-xs font-bold text-white/80">روز پشت‌سرهم تمرین · بیشترین: {{ $overall['longest'] }}</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums"><bdi dir="ltr">{{ $overall['finished'] }} / 24</bdi></b><span class="text-xs font-bold text-white/80">مأموریت تمام شده</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['listens'] }}</b><span class="text-xs font-bold text-white/80">بار گوش دادن</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['badges'] }}</b><span class="text-xs font-bold text-white/80">نشان گرفته</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['practiceDays'] }}</b><span class="text-xs font-bold text-white/80">روز تمرین در کل</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['words'] }}</b><span class="text-xs font-bold text-white/80">واژه در دفترچه</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['games'] }}</b><span class="text-xs font-bold text-white/80">بازی انجام شده</span></div>
                    <div class="{{ $panel }}"><b class="font-display block text-3xl font-extrabold tabular-nums">{{ $overall['joinedDaysAgo'] }}</b><span class="text-xs font-bold text-white/80">روز از شروع</span></div>
                </div>
            </section>

        </div>

        <div class="font-fa flex gap-2.5 px-4 pt-1 pb-[max(1rem,env(safe-area-inset-bottom))]" dir="rtl">
            @if ($pages['self'])
                <a href="{{ route('friends.profile', $friend) }}" wire:navigate class="flex-1 rounded-full bg-white py-3 text-center text-sm font-extrabold text-[#1d1b3a]">صفحهٔ من</a>
            @else
                <a href="{{ route('friends.conversation', $friend) }}" wire:navigate class="flex-1 rounded-full bg-white py-3 text-center text-sm font-extrabold text-[#1d1b3a]">پیام</a>
                <a href="{{ route('games.index') }}" wire:navigate class="flex-1 rounded-full bg-white/15 py-3 text-center text-sm font-extrabold">بازی</a>
            @endif
        </div>
    </div>
</div>
