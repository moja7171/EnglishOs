{{--
    Static (non-clickable) display of the learner's selected vocabulary
    words — for contexts with no text field to fill on click, unlike
    <x-vocabulary-chips> (e.g. a spoken-answer step). Just a reminder of
    which words are available to reach for.
--}}
@props(['words', 'label' => 'Words you picked — try to use some'])

@if (count($words))
    <div>
        <p class="text-xs font-semibold text-neutral-500 uppercase">{{ $label }}</p>
        <div class="mt-1 flex flex-wrap gap-1.5">
            @foreach ($words as $word)
                <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">{{ $word }}</span>
            @endforeach
        </div>
    </div>
@endif
