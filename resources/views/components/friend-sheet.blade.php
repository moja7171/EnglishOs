{{--
    Every friend in one bottom sheet with a search box: the shared way to
    pick a friend once the list is too long to show. A row opens a link
    ($hrefFor) or runs a Livewire action ($actionFor); the sheet closes on
    the tap either way.

    @param string $show Alpine boolean in the surrounding x-data (see <x-bottom-sheet>).
    @param string $label The sheet's title.
    @param \Illuminate\Support\Collection<int, \App\Models\User> $friends In the order to show them.
    @param (\Closure(\App\Models\User): string)|null $hrefFor Builds a row's link.
    @param (\Closure(\App\Models\User): string)|null $actionFor Builds a row's wire:click.
--}}
@props(['show', 'label', 'friends', 'hrefFor' => null, 'actionFor' => null])

@php
    $usernames = app(\App\Services\Usernames::class);
    $searchTexts = $friends->map(fn ($friend) => mb_strtolower($usernames->unifyLetters($friend->name)).' '.$friend->username)->values();
    $row = 'flex w-full cursor-pointer items-center gap-3 rounded-2xl px-2 py-2 text-left hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark';
@endphp

<x-bottom-sheet :show="$show" :label="$label" class="!gap-2">
    <x-slot:title>{{ $label }}</x-slot:title>

    <div x-data="{
        query: '',
        people: @js($searchTexts),
        get term() {
            return this.query.trim().replace(/^@/, '').toLowerCase().replace(/[يى]/g, 'ی').replace(/ك/g, 'ک');
        },
        matches(index) {
            return this.people[index].includes(this.term) || this.people[index].includes(this.term.replace(/ /g, '_'));
        },
    }">
        <label class="sticky top-0 z-10 mb-2 flex items-center gap-2 rounded-2xl border-2 border-line bg-surface px-3 py-2.5 focus-within:border-accent dark:border-line-dark dark:bg-surface-dark dark:focus-within:border-accent-dark">
            @svg('heroicon-o-magnifying-glass', 'h-5 w-5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
            <span class="sr-only">Name or @username</span>
            <input type="text" x-model="query" placeholder="Name or @username" autocapitalize="none" autocomplete="off" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm font-semibold text-ink focus:ring-0 focus:outline-none dark:text-ink-dark">
        </label>

        @foreach ($friends as $friend)
            @if ($hrefFor)
                <a href="{{ $hrefFor($friend) }}" wire:navigate wire:key="sheet-friend-{{ $friend->id }}" x-show="matches({{ $loop->index }})" class="{{ $row }}">
                    <x-friend-sheet-row :friend="$friend" />
                </a>
            @else
                <button type="button" wire:click="{{ $actionFor($friend) }}" x-on:click="{{ $show }} = false" wire:key="sheet-friend-{{ $friend->id }}" x-show="matches({{ $loop->index }})" class="{{ $row }}">
                    <x-friend-sheet-row :friend="$friend" />
                </button>
            @endif
        @endforeach

        <p x-show="! people.some((person, index) => matches(index))" x-cloak class="py-6 text-center text-sm text-ink-faint dark:text-ink-faint-dark">No friend with that name.</p>
    </div>
</x-bottom-sheet>
