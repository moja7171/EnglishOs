{{--
    The avatar in the header and the menu it opens: only what is secondary
    to the bottom-nav tabs — Progress and Profile & settings (plus Admin). The guides, the theme and sound switches and Sign out live on
    the Profile & settings page.

    A thin ring around the avatar fills with the 96-day program ("Day N of
    96", ProgramPlanner's programDay), so how far the learner has come is
    on every screen without a tap; Progress opens the rest.

    Friends is a bottom-nav tab with its own count (see <x-bottom-nav>).
--}}
@php
    $user = auth()->user();

    $plan = app(\App\Services\ProgramPlanner::class)->plan($user);
    $ringLength = 2 * M_PI * 15;

    $menuLinkClass = 'flex items-center gap-3 px-4 py-3 text-sm font-semibold text-ink-soft transition-colors hover:bg-surface-sunken hover:text-ink dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark dark:hover:text-ink-dark';
@endphp

<div class="relative" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" x-data="{ open: false }">
    <button
        type="button"
        x-on:click="open = ! open"
        aria-haspopup="menu"
        aria-label="Account menu · Day {{ $plan['programDay'] }} of {{ $plan['totalDays'] }}"
        class="relative inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full transition-opacity hover:opacity-80"
    >
        <svg viewBox="0 0 34 34" class="absolute inset-0 h-full w-full -rotate-90" aria-hidden="true">
            <circle cx="17" cy="17" r="15" fill="none" stroke-width="2" class="stroke-line dark:stroke-line-dark" />
            <circle
                cx="17" cy="17" r="15" fill="none" stroke-width="2" stroke-linecap="round"
                stroke-dasharray="{{ round($ringLength * $plan['programDay'] / $plan['totalDays'], 2) }} {{ round($ringLength, 2) }}"
                class="stroke-accent dark:stroke-accent-dark"
            />
        </svg>
        <x-user-avatar :user="$user" class="h-7 w-7 text-[11px]" />
    </button>

    <x-menu-panel role="menu" aria-label="Account menu" class="sm:py-1">
        <div class="flex items-center gap-3 border-b border-line px-4 py-2.5 dark:border-line-dark">
            <x-user-avatar :user="$user" class="h-8 w-8 text-xs" />
            <p class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $user->name }}</p>
        </div>

        <a href="{{ route('progress.index') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-chart-bar', 'h-5 w-5')
            <span class="flex-1">Progress</span>
            <span class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Day {{ $plan['programDay'] }} of {{ $plan['totalDays'] }}</span>
        </a>
        <a href="{{ route('profile') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
            @svg('heroicon-o-user-circle', 'h-5 w-5') Profile &amp; settings
        </a>
        @if ($user->is_admin)
            <a href="{{ route('admin.index') }}" wire:navigate x-on:click="open = false" class="{{ $menuLinkClass }}">
                @svg('heroicon-o-chart-bar', 'h-5 w-5') Admin
            </a>
        @endif
    </x-menu-panel>
</div>
