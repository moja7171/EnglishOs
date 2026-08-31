{{-- Visual chrome only — deliberately has no built-in reactivity model. The
     caller supplies the fill bar (as the default slot, with its own
     class/:class and style/:style) and an optional label, since some
     progress bars here are server-rendered and others are Alpine-reactive. --}}
<div>
    <div class="h-1.5 w-full overflow-hidden rounded-full bg-neutral-200 dark:bg-neutral-800">
        {{ $slot }}
    </div>
    @isset($label)
        <div class="mt-1.5">{{ $label }}</div>
    @endisset
</div>
