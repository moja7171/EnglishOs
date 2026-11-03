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

@php
    // Built as one string on purpose: Blade's newlines between the pieces
    // would show as a space before a full stop or a closing quote.
    $html = collect(preg_split('/\*\*(.+?)\*\*/', $text, -1, PREG_SPLIT_DELIM_CAPTURE))
        ->map(fn (string $part, int $index) => $index % 2 === 1
            ? '<strong class="font-bold text-accent-ink dark:text-accent-ink-dark">'.e($part).'</strong>'
            : e($part))
        ->implode('');
@endphp
<span {{ $attributes }}>{!! $html !!}</span>