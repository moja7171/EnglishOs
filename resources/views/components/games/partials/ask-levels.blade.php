{{--
    Ask & Answer: the level of the questions I ask and of the answers I give
    (1 simple · 2 with detail · 3 challenge). A level changes how the
    sentence is built and how long it is, never how hard the words are.
    Needs $levels (['q' => 1-3, 'a' => 1-3]) and $action, the name of a
    method on the page: setAskLevel(string $side, int $level). With a
    friend only the questions have a level: the answer follows the
    question that was asked (pass $sides = ['q']).
--}}
@php
    $rows = array_intersect_key(['q' => 'Questions I ask', 'a' => 'Answers I give'], array_flip($sides ?? ['q', 'a']));
    $names = [1 => 'Simple', 2 => 'More detail', 3 => 'Challenge'];
@endphp

<div class="card space-y-3 p-4 text-start">
    <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">Level</p>
    @foreach ($rows as $side => $label)
        <div wire:key="ask-level-{{ $side }}" class="space-y-1.5">
            <p class="text-sm font-bold text-ink dark:text-ink-dark">{{ $label }}</p>
            <div class="grid grid-cols-3 gap-1.5 rounded-2xl bg-surface-sunken p-1 dark:bg-surface-sunken-dark">
                @foreach ($names as $level => $name)
                    <button type="button" wire:key="ask-level-{{ $side }}-{{ $level }}" wire:click="{{ $action }}('{{ $side }}', {{ $level }})" @class([
                        'h-11 cursor-pointer rounded-xl text-[13px]',
                        'bg-surface font-extrabold text-ink shadow-sm dark:bg-surface-dark dark:text-ink-dark' => ($levels[$side] ?? 1) === $level,
                        'font-bold text-ink-soft dark:text-ink-soft-dark' => ($levels[$side] ?? 1) !== $level,
                    ])>{{ $level }} · {{ $name }}</button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
