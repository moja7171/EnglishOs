{{--
    A question or an answer that is heard first: the speaker plays it (and
    plays it again) and the text stays blurred until the learner taps it
    (resources/js/app.js, store heardText). On a phone with no English voice
    the text is shown.

    @param string $text The words, plain text.
    @param string|null $autoWhen Raw Alpine expression: the text is read aloud
        whenever it is true (e.g. "screen === 2"), once when it first is.
    @param bool $hint Show the "Tap to read the text" line while it is blurred.
--}}
@props(['text', 'autoWhen' => null, 'hint' => true])

<div
    x-data="heardText(@js($text))"
    @if ($autoWhen) x-effect="if ({{ $autoWhen }}) $nextTick(() => say())" @endif
    {{ $attributes->class('flex flex-col gap-1') }}
>
    <div class="flex items-start gap-2">
        <span
            role="button"
            tabindex="0"
            x-on:click="reveal()"
            x-on:keydown.enter="reveal()"
            x-bind:class="visible ? '' : 'cursor-pointer blur-md select-none'"
            class="min-w-0 flex-1 transition"
        >{{ $text }}</span>
        <button
            type="button"
            x-on:click.stop="say()"
            title="Hear it"
            class="relative inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent-soft p-2 text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark"
        >
            <span class="sr-only">Hear “{{ $text }}”</span>
            @svg('heroicon-o-speaker-wave', 'h-5 w-5')
        </button>
    </div>
    @if ($hint)
        <button type="button" x-show="! visible" x-on:click="reveal()" class="cursor-pointer self-start text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Tap to read the text</button>
    @endif
</div>
