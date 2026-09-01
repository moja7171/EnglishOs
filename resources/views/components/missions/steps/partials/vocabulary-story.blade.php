{{-- Shared interactive story rendering — used both for the initial pick-8
     screen and the "show story again" reference during practice, since
     adding a new word must stay possible from either place. --}}
@foreach ($storyParagraphs as $paragraph)
    <div class="mt-4 first:mt-0">
        <span class="inline-block rounded-full bg-ink px-2.5 py-0.5 text-[11px] font-bold tracking-wide text-ground uppercase dark:bg-ink-dark dark:text-ground-dark">{{ $paragraph['heading'] }}</span>
        <p class="mt-2 text-sm leading-loose text-ink dark:text-ink-dark">
            @foreach ($paragraph['segments'] as $segment)
                @if ($segment['type'] === 'text')
                    {{ $segment['value'] }}
                @else
                    @php $isSelected = in_array($segment['value'], $selectedWords, true); @endphp
                    @if ($readOnly ?? false)
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold' => true,
                            'bg-success text-white dark:bg-success-dark' => $isSelected,
                            'text-ink-faint dark:text-ink-faint-dark' => ! $isSelected,
                        ])>@if ($isSelected) @svg('heroicon-o-check', 'h-3 w-3') @endif{{ $segment['value'] }}</span>
                    @else
                        <button
                            type="button"
                            wire:click="toggleWord('{{ addslashes($segment['value']) }}')"
                            wire:loading.attr="disabled"
                            wire:target="toggleWord"
                            title="{{ $component->wordMeaning($segment['value']) }}"
                            @class([
                                'inline-flex cursor-pointer items-center gap-1 rounded-full px-2 py-0.5 font-semibold shadow-sm transition-all duration-150 hover:scale-105 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:scale-100' => true,
                                'bg-success text-white hover:opacity-90 dark:bg-success-dark' => $isSelected,
                                'bg-accent-soft text-accent-ink hover:opacity-80 dark:bg-accent-soft-dark dark:text-accent-ink-dark' => ! $isSelected,
                            ])
                        >@if ($isSelected) @svg('heroicon-o-check', 'h-3 w-3') @endif{{ $segment['value'] }}</button>
                    @endif
                @endif
            @endforeach
        </p>
    </div>
@endforeach
