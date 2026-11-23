{{--
    One Persian paragraph that may hold English ("از have / has استفاده کن"):
    right-to-left, with every English run isolated so the two never swap
    places (see App\Services\BidiText).

    @param string $text The Persian text.
    @param string $tag 'span' inside a button or a line of text, where a <p> is not allowed.
    @param bool $markup True for developer-written seed text that may hold
        <strong> / <em> / <br>; false (default) for text from a learner or the AI.
--}}
@props(['text', 'markup' => false, 'tag' => 'p'])

<{{ $tag }} dir="rtl" {{ $attributes->class(['font-fa leading-loose', 'block' => $tag === 'span']) }}>{{ $markup ? app(\App\Services\BidiText::class)->fromMarkup($text) : app(\App\Services\BidiText::class)->fromPlain($text) }}</{{ $tag }}>
