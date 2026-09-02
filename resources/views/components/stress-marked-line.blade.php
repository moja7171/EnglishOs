{{--
    Renders a sentence with its naturally-stressed content words bolded —
    the same **word** markup convention Vocabulary Builder's story already
    uses for selectable phrases, reused here for a different purpose
    (sentence rhythm, not vocabulary). Authored once per shadow line in
    MissionSeeder — this component only renders the markup, it never
    infers stress itself.

    @param string $text Raw text with **stressed words** marked.
--}}
@props(['text'])

<span {{ $attributes }}>
    @foreach (preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $index => $part)
        @if ($part !== '')
            @if ($index % 2 === 1)
                <strong class="font-bold text-accent-ink dark:text-accent-ink-dark">{{ $part }}</strong>
            @else
                {{ $part }}
            @endif
        @endif
    @endforeach
</span>
