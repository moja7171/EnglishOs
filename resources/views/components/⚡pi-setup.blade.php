<?php

use App\Services\PiPrompts;
use Livewire\Component;

/**
 * A one-time screen before a learner's first mission: set up 3 persistent
 * chats in Pi (or any other live-voice AI assistant), each with a fixed
 * persona (teacher/language partner/pronunciation coach — see
 * App\Services\PiPrompts). Individual missions then just say "paste this
 * in your Teacher chat" via <x-pi-practice-card> — this page is the only
 * place the setup message itself is shown.
 *
 * The home route (routes/web.php) forces this on anyone whose
 * pi_onboarded_at is still null; existing users are grandfathered by that
 * column's migration, so the forced redirect only ever catches someone
 * truly before Mission 1. The route itself has no such gate, though — it's
 * also linked from the account menu so anyone can come back and re-copy
 * the setup messages later.
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

@php
    $roles = app(PiPrompts::class)->onboardingRoles(auth()->user());
@endphp

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Set up your Pi practice partners</h1>
        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
            Pi is a free, separate voice AI app — you talk to it live and it writes down both sides as you go.
            Missions will send you to it for real spoken practice. Open Pi now and start 3 separate chats, one per
            card below, and paste in that card's message to set its role. You only do this once.
        </p>
    </header>

    <div class="space-y-3">
        @foreach ($roles as $role)
            <div
                x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.setup.value); this.copied = true; setTimeout(() => this.copied = false, 1500) } }"
                class="rounded-2xl border border-line p-4 dark:border-line-dark"
            >
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $role['label'] }}</p>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $role['when'] }}</p>
                <textarea
                    x-ref="setup"
                    readonly
                    rows="3"
                    class="mt-2 w-full resize-none rounded-lg border border-line bg-surface-sunken px-2 py-1.5 text-xs text-ink-soft dark:border-line-dark dark:bg-surface-sunken-dark dark:text-ink-soft-dark"
                >{{ $role['setupMessage'] }}</textarea>
                <button
                    type="button"
                    x-on:click="copy()"
                    class="mt-2 cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                >
                    <span x-show="! copied">Copy message</span>
                    <span x-show="copied" x-cloak>Copied!</span>
                </button>
            </div>
        @endforeach
    </div>

    <button
        type="button"
        wire:click="done"
        wire:loading.attr="disabled"
        wire:target="done"
        class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
    >Done — I created the 3 chats @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
</div>
