{{-- Shared interactive story rendering — used both for the initial pick-8
     screen and the "show story again" reference during practice, since
     adding a new word must stay possible from either place. --}}
@foreach ($storyParagraphs as $paragraph)
    <div class="mt-4 first:mt-0">
        <span class="inline-block rounded-full bg-neutral-900 px-2.5 py-0.5 text-[11px] font-bold tracking-wide text-white uppercase dark:bg-white dark:text-neutral-900">{{ $paragraph['heading'] }}</span>
        <p class="mt-2 text-sm leading-loose">
            @foreach ($paragraph['segments'] as $segment)
                @if ($segment['type'] === 'text')
                    {{ $segment['value'] }}
                @else
                    @php $isSelected = in_array($segment['value'], $selectedWords, true); @endphp
                    @if ($readOnly ?? false)
                        <span @class([
                            'rounded-full px-2 py-0.5 font-semibold' => true,
                            'bg-green-600 text-white' => $isSelected,
                            'text-neutral-400' => ! $isSelected,
                        ])>{{ $isSelected ? '✓ ' : '' }}{{ $segment['value'] }}</span>
                    @else
                        <button
                            type="button"
                            wire:click="toggleWord('{{ addslashes($segment['value']) }}')"
                            wire:loading.attr="disabled"
                            wire:target="toggleWord"
                            title="{{ $component->wordMeaning($segment['value']) }}"
                            @class([
                                'cursor-pointer rounded-full px-2 py-0.5 font-semibold shadow-sm transition-all duration-150 hover:scale-105 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:scale-100' => true,
                                'bg-green-600 text-white hover:bg-green-500' => $isSelected,
                                'bg-neutral-200 text-neutral-700 hover:bg-neutral-300 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700' => ! $isSelected,
                            ])
                        >{{ $isSelected ? '✓ ' : '' }}{{ $segment['value'] }}</button>
                    @endif
                @endif
            @endforeach
        </p>
    </div>
@endforeach
