{{--
    Static (non-clickable) display of the learner's selected vocabulary
    words — for contexts with no text field to fill on click, unlike
    <x-vocabulary-chips> (e.g. a spoken-answer step). Just a reminder of
    which words are available to reach for.
--}}
@props(['words', 'label' => 'Words you picked — try to use some'])

@if (count($words))
    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $label }}</p>
        <div class="mt-1 flex flex-wrap gap-1.5">
            @foreach ($words as $word)
                <span class="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-semibold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $word }}</span>
            @endforeach
        </div>
    </div>
@endif
