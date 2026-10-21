{{-- The one full-screen picture viewer, mounted once in the app layout.
     Anything can open it by dispatching `zoom-image` ({ url, alt }) —
     see <x-zoomable-image>. Tap the picture, the backdrop, ✕ or Esc to close. --}}
<div
    x-data="{ open: false, url: '', alt: '' }"
    x-on:zoom-image.window="url = $event.detail.url; alt = $event.detail.alt ?? ''; open = true"
    x-on:keydown.escape.window="open = false"
    x-effect="document.body.style.overflow = open ? 'hidden' : ''"
    x-show="open"
    x-cloak
    x-transition.opacity
    x-on:click="open = false"
    class="fixed inset-0 z-[60] flex items-center justify-center bg-black/85 p-3"
    role="dialog"
    aria-modal="true"
    aria-label="Picture"
>
    <img :src="url" :alt="alt" class="max-h-full max-w-full rounded-xl object-contain">
    <button
        type="button"
        x-on:click.stop="open = false"
        aria-label="Close picture"
        class="absolute top-3 right-3 inline-flex h-11 w-11 cursor-pointer items-center justify-center rounded-full bg-black/60 text-white transition-colors hover:bg-black/80"
    >@svg('heroicon-o-x-mark', 'h-5 w-5')</button>
</div>
