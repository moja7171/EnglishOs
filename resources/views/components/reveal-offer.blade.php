{{-- Shown once a field has failed TracksCheckAttempts::revealThreshold()
     times — three normally, two once the learner is struggling. The one
     place in the app that offers to write the correction directly, and
     only once the learner has genuinely tried. --}}
@props([
    'show',
    'revealMethod',
    'declineMethod',
    'index',
    'wireTarget',
    'label' => 'Want me to write the correct one for you?',
    // MissionRun::isStruggling(). Only ever adds the "come back later"
    // line below — it never changes what this step requires.
    'struggling' => false,
])

@if ($show)
    <div class="mt-2 rounded-xl border border-accent-soft bg-accent-soft/60 px-3 py-2 dark:border-accent-soft-dark dark:bg-accent-soft-dark/60">
        <p class="text-sm text-accent-ink dark:text-accent-ink-dark">{{ $label }}</p>
        <div class="mt-2 flex gap-2">
            <button
                type="button"
                wire:click="{{ $revealMethod }}({{ \Illuminate\Support\Js::from($index) }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded-full bg-ink px-3 py-1 text-xs font-semibold text-ground transition-colors hover:opacity-85 disabled:pointer-events-none disabled:opacity-50 dark:bg-ink-dark dark:text-ground-dark"
            >Yes, show me</button>
            <button
                type="button"
                wire:click="{{ $declineMethod }}({{ \Illuminate\Support\Js::from($index) }})"
                wire:loading.attr="disabled"
                wire:target="{{ $wireTarget }}"
                class="cursor-pointer rounded-full border border-accent-ink/30 px-3 py-1 text-xs font-semibold text-accent-ink transition-colors hover:bg-accent-soft disabled:pointer-events-none disabled:opacity-50 dark:border-accent-ink-dark/30 dark:text-accent-ink-dark dark:hover:bg-accent-soft-dark"
            >No, I'll keep trying</button>
        </div>

        @if ($struggling)
            {{-- The third way out, and the reason it appears only here:
                 a learner stuck on one item can leave it and come back,
                 and most people don't realise their work is already
                 saved. Stated as a fact about the app, never as
                 "you seem to be having trouble" — naming the struggle is
                 exactly what would make it sting. --}}
            <p class="mt-2 text-xs text-accent-ink/80 dark:text-accent-ink-dark/80">
                No rush either way — everything you've written is saved, so you can leave this one and come back to it later.
            </p>
        @endif
    </div>
@endif
