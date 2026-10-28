<?php

use App\Services\PiPrompts;
use Livewire\Component;

/**
 * A short guide to the daily voice practice with Pi (or any other
 * live-voice AI assistant). Nothing to set up there — every day's prompt on
 * /pi is self-contained (see App\Services\PiPrompts) — so this only explains
 * how a session runs.
 *
 * Offered as a one-line suggestion on home until the learner has been
 * through it once (pi_onboarded_at, set by done()), never forced; also
 * linked from the account menu and the practice page.
 */
new class extends Component
{
    public function done(): void
    {
        auth()->user()->forceFill(['pi_onboarded_at' => now()])->save();

        $this->redirect(route('home'), navigate: true);
    }
};
?>

<div
    class="mx-auto max-w-lg space-y-4 p-4"
    x-data="{
        card: 0,
        last: 2,
        go(index) { this.$refs.track.scrollTo({ left: this.$refs.track.clientWidth * index, behavior: 'smooth' }) },
        track() { this.card = Math.round(this.$refs.track.scrollLeft / this.$refs.track.clientWidth) },
    }"
>
    <header class="flex items-center gap-2">
        <a href="{{ route('home') }}" wire:navigate aria-label="Close" class="-ms-1 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-x-mark', 'h-5 w-5')</a>
        <h1 class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">How Daily talk works</h1>
    </header>

    <div x-ref="track" x-on:scroll.debounce.50ms="track()" class="flex snap-x snap-mandatory gap-3 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <section wire:key="pi-card-0" class="flex min-h-[26rem] w-full shrink-0 snap-center flex-col items-center gap-3 rounded-3xl bg-linear-160 from-[#ff8f6b] via-[#c0527a] to-[#3a3466] p-6 text-center text-white">
            <span class="mt-4 inline-flex h-20 w-20 items-center justify-center rounded-3xl bg-white/20">@svg('heroicon-o-microphone', 'h-10 w-10')</span>
            <h2 class="text-xl font-extrabold">Talk with a voice AI</h2>
            <p class="text-base leading-7 font-semibold opacity-90">10 minutes at the end of your day.<br>Free, nothing to set up.</p>
            <div class="mt-auto flex w-full gap-2">
                <a href="https://pi.ai" target="_blank" rel="noopener noreferrer" x-data x-on:click="if (/Android/i.test(navigator.userAgent)) { $event.preventDefault(); window.location.href = {{ App\Services\PiPrompts::piAndroidIntentJs() }} }" class="flex-1 rounded-2xl bg-white/20 px-3 py-3 text-base font-extrabold">Pi</a>
                <a href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer" class="flex-1 rounded-2xl bg-white/20 px-3 py-3 text-base font-extrabold">Gemini Live</a>
            </div>
        </section>

        <section wire:key="pi-card-1" class="flex min-h-[26rem] w-full shrink-0 snap-center flex-col gap-3 rounded-3xl bg-linear-160 from-[#4cceb0] via-[#1f7a8c] to-[#3a3466] p-6 text-white">
            <span class="mt-2 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20">@svg('heroicon-o-clipboard-document', 'h-7 w-7')</span>
            <h2 class="text-xl font-extrabold">Copy, then talk</h2>
            <ol class="mt-2 space-y-2.5 text-base font-bold">
                <li class="flex items-center gap-3 rounded-2xl bg-white/15 px-4 py-3"><span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/25 text-sm">1</span>Copy today's prompt here</li>
                <li class="flex items-center gap-3 rounded-2xl bg-white/15 px-4 py-3"><span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/25 text-sm">2</span>In the app, turn on voice mode first</li>
                <li class="flex items-center gap-3 rounded-2xl bg-white/15 px-4 py-3"><span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-white/25 text-sm">3</span>Send the prompt and talk</li>
            </ol>
        </section>

        <section wire:key="pi-card-2" class="flex min-h-[26rem] w-full shrink-0 snap-center flex-col gap-3 rounded-3xl bg-linear-160 from-[#8b7ce0] via-[#5b4fa8] to-[#3a3466] p-6 text-white">
            <span class="mt-2 inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white/20">@svg('heroicon-o-chat-bubble-left-right', 'h-7 w-7')</span>
            <h2 class="text-xl font-extrabold">Talk, then fix</h2>
            <p class="text-base leading-7 font-semibold opacity-90">About 10 minutes, then 2-3 short fixes. Say one of these on its own, any time:</p>
            <div class="grid grid-cols-2 gap-2">
                @foreach (PiPrompts::CONTROL_WORDS as $word => $meaning)
                    <span class="rounded-2xl bg-white/15 px-3 py-2 text-sm leading-5"><b class="block text-base">{{ $word }}</b><span class="opacity-80">{{ $meaning }}</span></span>
                @endforeach
            </div>
        </section>
    </div>

    <div class="flex justify-center gap-1.5" aria-hidden="true">
        @foreach (range(0, 2) as $index)
            <button type="button" tabindex="-1" x-on:click="go({{ $index }})" class="h-2 cursor-pointer rounded-full transition-all" x-bind:class="card === {{ $index }} ? 'w-5 bg-accent dark:bg-accent-dark' : 'w-2 bg-line dark:bg-line-dark'"></button>
        @endforeach
    </div>

    <div class="pt-1">
        <button type="button" x-show="card < last" x-on:click="go(card + 1)" class="block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] dark:bg-accent-dark">Next</button>
        <button
            type="button"
            x-show="card === last"
            x-cloak
            wire:click="done"
            wire:loading.attr="disabled"
            wire:target="done"
            class="block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
        >Got it</button>
    </div>
</div>
