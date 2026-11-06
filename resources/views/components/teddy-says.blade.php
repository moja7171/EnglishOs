{{--
    One of Teddy's turns as a person's would look: his face, his name, three
    bouncing dots for about a second as if he were thinking, then his words
    on screen while he says them aloud in his own voice (window.eosVoice.teddy,
    see resources/js/app.js). The face rings while he talks.

    @param string $say The words to speak, plain text.
    @param string|null $label The small line over his words (default "Teddy").
--}}
@props(['say', 'label' => null])

<div
    x-data="{ thinking: true, talking: false }"
    x-init="setTimeout(() => { thinking = false; talking = true; window.eosVoice?.teddy(@js($say), { onEnd: () => talking = false }) }, 900)"
    {{ $attributes->class(['flex items-start gap-2.5']) }}
>
    <x-teddy-avatar class="h-9 w-9 transition-shadow" x-bind:class="talking ? 'ring-2 ring-accent dark:ring-accent-dark' : ''" />
    <div class="min-w-0 flex-1 rounded-2xl rounded-ss-md bg-surface-sunken p-3 text-start dark:bg-surface-sunken-dark">
        <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $label ?? 'Teddy' }}</p>
        <div x-show="thinking" class="flex items-center gap-1 py-2" role="status">
            <span class="sr-only">Teddy is thinking</span>
            <span class="h-2 w-2 animate-bounce rounded-full bg-ink-faint dark:bg-ink-faint-dark"></span>
            <span class="h-2 w-2 animate-bounce rounded-full bg-ink-faint [animation-delay:150ms] dark:bg-ink-faint-dark"></span>
            <span class="h-2 w-2 animate-bounce rounded-full bg-ink-faint [animation-delay:300ms] dark:bg-ink-faint-dark"></span>
        </div>
        <div x-show="! thinking" x-cloak>
            {{ $slot }}
            <div class="mt-2"><x-speak-button :text="$say" voice="teddy" /></div>
        </div>
    </div>
</div>
