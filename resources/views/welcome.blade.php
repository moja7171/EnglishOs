{{-- The first screen a visitor sees: what the app is, then Get started or
     Sign in. --}}
<x-layouts.app>
    <div class="mx-auto flex min-h-[calc(100dvh-8rem)] max-w-sm flex-col gap-4 p-6">
        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <span class="inline-flex h-36 w-36 items-center justify-center rounded-[2.5rem] bg-gradient-to-br from-[#ffb17a] via-[#ff6b4a] to-[#8b7ce0] text-6xl" aria-hidden="true">🗣️</span>
            <h1 class="mt-3 font-display text-3xl leading-tight font-extrabold text-ink dark:text-ink-dark">Speak English with confidence</h1>
            <p class="text-base text-ink-soft dark:text-ink-soft-dark">24 missions · ~1 hour a day</p>
        </div>

        <div class="space-y-3 pb-2">
            <a href="{{ route('register') }}" wire:navigate class="block w-full rounded-2xl bg-accent py-3.5 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 dark:bg-accent-dark">Get started</a>
            <a href="{{ route('login') }}" wire:navigate class="card block w-full rounded-2xl py-3.5 text-center text-base font-extrabold text-ink dark:text-ink-dark">I have an account</a>
        </div>
    </div>
</x-layouts.app>
