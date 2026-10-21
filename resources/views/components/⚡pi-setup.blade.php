<?php

use App\Services\PiPrompts;
use Livewire\Component;

/**
 * A one-time screen before a learner's first mission: a short guide to the
 * daily voice practice with Pi (or any other live-voice AI assistant).
 * Nothing to set up there — every day's prompt on /pi is self-contained
 * (see App\Services\PiPrompts) — so this only explains how a session runs.
 *
 * The home route (routes/web.php) forces this on anyone whose
 * pi_onboarded_at is still null; existing users are grandfathered by that
 * column's migration, so the forced redirect only ever catches someone
 * truly before Mission 1. The route itself has no such gate, though — it's
 * also linked from the account menu and the practice page so anyone can
 * come back to it later.
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

<div class="mx-auto max-w-2xl space-y-6 p-6">
    <header class="border-b border-line pb-4 dark:border-line-dark">
        <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">How voice practice works</h1>
        <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">
            Ten minutes a day of real speaking with a free voice AI app, at the end of your day. Nothing to set up.
        </p>
    </header>

    <section class="card space-y-3 p-4" aria-label="How it works">
        <ol class="list-decimal space-y-1.5 ps-5 text-sm text-ink-soft dark:text-ink-soft-dark">
            <li>
                Install a voice AI app —
                <a href="https://pi.ai" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Pi</a>
                or
                <a href="https://gemini.google.com/app" target="_blank" rel="noopener noreferrer" class="font-semibold text-accent-ink underline dark:text-accent-ink-dark">Gemini Live</a>.
            </li>
            <li>Each day, open Voice practice here and copy the day's prompt.</li>
            <li>In the app, turn on voice mode first, then send the prompt.</li>
            <li>The AI tells you the plan, then you talk. About 10 minutes, then 2-3 short fixes.</li>
        </ol>

        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">
            Say these any time:
            @foreach (PiPrompts::CONTROL_WORDS as $word => $meaning)
                <span class="font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $word }}</span> ({{ $meaning }}){{ $loop->last ? '.' : ',' }}
            @endforeach
        </p>
    </section>

    <button
        type="button"
        wire:click="done"
        wire:loading.attr="disabled"
        wire:target="done"
        class="inline-flex cursor-pointer items-center gap-1 rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
    >Got it @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5')</button>
</div>
