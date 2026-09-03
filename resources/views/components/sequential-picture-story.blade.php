{{--
    A numbered strip of images telling a story in order — the classic
    picture-sequencing exercise (Cambridge KET/PET style) for practicing
    narrative sequencing language ("first", "then", "after that") with
    past-tense verbs. Purely presentational, content-driven via $images —
    no AI-check/interaction logic bundled here, since that belongs to
    whichever step actually uses it.

    NOT wired into M01: M01's grammar point is Present Simple, not past
    narrative — this genuinely belongs to a future mission that teaches
    sequencing a past event. Ships now as a ready building block, same
    reasoning as <x-grammar-timeline>.
--}}
@props(['images' => []])

<div {{ $attributes->class(['flex gap-3 overflow-x-auto pb-1']) }}>
    @foreach ($images as $index => $image)
        <div class="w-36 shrink-0">
            <div class="relative">
                <img
                    src="{{ $image['url'] }}"
                    alt="{{ $image['caption'] ?? 'Step '.($index + 1) }}"
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
