{{--
    Clickable vocabulary-word chips — tapping one fills the next empty
    slot in a Livewire array field (matching Listening's existing
    target-phrase chips), instead of the word just sitting in inert text
    the learner has to retype themselves.

    @param array $words The learner's selected vocabulary words.
    @param string $field The Livewire array property to fill, e.g. "sentences".
    @param string|null $refPrefix If the field's inputs have x-ref="{prefix}{index}",
        passing that prefix here focuses the filled input after inserting.
    @param string|null $onInsert Extra Alpine statements run right after the
        field is set — e.g. to update the caller's own `filled`/`dismissed`
        tracking arrays. `idx` is available as the index that was filled.
--}}
@props(['words', 'field', 'refPrefix' => null, 'onInsert' => null])

@if (count($words))
    <div class="flex flex-wrap gap-1.5">
        @foreach ($words as $word)
            <button
                type="button"
                x-on:click="
                    let idx = $wire.{{ $field }}.findIndex(v => !v || v.trim() === '');
                    if (idx === -1) idx = 0;
                    $wire.set('{{ $field }}.' + idx, '{{ addslashes(ucfirst($word)) }}');
                    {{ $onInsert }}
                    @if ($refPrefix)
                        $nextTick(() => $refs['{{ $refPrefix }}' + idx]?.focus());
                    @endif
                "
                class="cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >{{ $word }}</button>
        @endforeach
    </div>
@endif
