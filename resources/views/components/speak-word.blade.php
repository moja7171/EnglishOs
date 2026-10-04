{{--
    A word, phrase or sentence with a small speaker icon right beside it —
    tapping the icon reads it aloud (window.eosVoice, SpeechSynthesis — see
    resources/js/app.js and <x-speak-button>, which this is the inline
    sibling of). Replaces the old hidden double-tap gesture: an icon is
    visible, works with a keyboard and a screen reader, and never fights
    zoom or text selection on mobile. Fails silently on a browser without
    speech support, same as everywhere else.

    Any class passed in styles the whole wrapper, so a chip's border,
    padding and colors simply wrap the text and icon together.

    @param string $word  The text to speak, and to show unless a slot is given.
    @param string $size  'md' (default) or 'sm' for tight spots like chips.
    @param bool  $block  Lay the wrapper out as a block-level row instead of inline.
    @param bool  $top    Pin the icon to the first line (for a sentence that wraps).
    Slot (optional): markup to show instead of the plain $word — e.g. a
    sentence with its target word in bold. The icon still speaks $word.
--}}
@props(['word', 'size' => 'md', 'block' => false, 'top' => false])

<span {{ $attributes->class([$block ? 'flex' : 'inline-flex', $top ? 'items-start' : 'items-center', $size === 'sm' ? 'gap-1' : 'gap-1.5']) }}>
    <span>{{ $slot->isNotEmpty() ? $slot : $word }}</span>
    <button
        type="button"
        x-data
        x-on:click.stop.prevent="window.eosVoice?.speak($el.dataset.text)"
        data-text="{{ $word }}"
        title="Hear it"
        class="inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-faint transition-colors hover:bg-surface-sunken hover:text-accent-ink dark:text-ink-faint-dark dark:hover:bg-surface-sunken-dark dark:hover:text-accent-ink-dark {{ $size === 'sm' ? 'p-0.5' : 'p-1' }}"
    >
        <span class="sr-only">Hear “{{ $word }}”</span>
        @svg('heroicon-o-speaker-wave', $size === 'sm' ? 'h-3 w-3' : 'h-4 w-4')
    </button>
</span>
