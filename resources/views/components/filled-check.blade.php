{{--
    A small green checkmark that appears next to an input once it has a
    value — reused across every step with per-item fill tracking.

    @param string $show Raw Alpine expression for x-show, e.g. "filled[0]".
--}}
@props(['show'])

<span x-show="{{ $show }}" class="inline-flex shrink-0 text-green-600">
    @svg('heroicon-o-check-circle', 'h-4 w-4')
</span>
