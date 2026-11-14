{{--
    The Persian line under one English sentence, shown while the "fa" store
    is on (see <x-fa-toggle>).

    @param string $text The Persian sentence.
--}}
@props(['text'])

<p dir="rtl" x-show="$store.fa.on" x-cloak {{ $attributes->class('font-fa mt-0.5 px-1 text-[0.9em] leading-loose text-sky-800 dark:text-sky-300') }}>{{ $text }}</p>
