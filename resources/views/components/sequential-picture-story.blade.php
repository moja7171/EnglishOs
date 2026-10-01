{{--
    A numbered strip of images telling a story in order — the classic
    picture-sequencing exercise (Cambridge KET/PET style) for practicing
    narrative sequencing language ("first", "then", "after that"). Purely
    presentational, content-driven via $images — no AI-check/interaction
    logic bundled here, since that belongs to whichever step actually uses
    it (currently M01's Picture Story step, narrated in Present Simple).

    Each image may carry an 'alt' (a neutral scene description for screen
    readers, safe to show even when 'caption' itself must stay hidden from
    the learner — see the Picture Story step's own docblock) separately
    from 'caption' (a visible label shown under the image, for callers
    that do want one on screen).
--}}
@props(['images' => []])

<div {{ $attributes->class(['relative rounded-2xl p-1.5']) }} style="background-image: var(--mood-texture); background-size: var(--mood-texture-size);">
    <div class="flex gap-3 overflow-x-auto pb-1">
        @foreach ($images as $index => $image)
            <div class="w-36 shrink-0">
                <div class="relative">
                    <img
                        src="{{ $image['url'] }}"
                        alt="{{ $image['alt'] ?? $image['caption'] ?? 'Step '.($index + 1) }}"
                        class="h-28 w-36 rounded-xl object-cover"
                    >
                    <span class="absolute top-1.5 left-1.5 inline-flex h-5 w-5 items-center justify-center rounded-full bg-ink/80 text-[11px] font-bold text-white dark:bg-ink-dark/80">
                        {{ $index + 1 }}
                    </span>
                </div>
                @if ($image['caption'] ?? null)
                    <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">{{ $image['caption'] }}</p>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Fade hint on the right edge so a 4-image strip that overflows the
         viewport (routine on mobile) reads as "scroll for more", not as
         the whole story. --}}
    @if (count($images) > 2)
        <div class="pointer-events-none absolute top-1.5 right-1.5 bottom-2.5 w-10 rounded-r-xl bg-gradient-to-l from-surface to-transparent dark:from-surface-dark" aria-hidden="true"></div>
    @endif
</div>
