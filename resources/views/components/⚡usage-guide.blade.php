<?php

use App\Services\UsageGuides;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * A part's usage guide (config/usage_guides.php) in a bottom sheet. It opens
 * by itself until the learner confirms it with «خواندم»; closing it any other
 * way (swipe, Back, the dimmed page) brings it back next time. After that only
 * <x-usage-guide-button> opens it, through the 'usage-guide-open' event.
 *
 * Whether it opens is decided once here, and the sheet lives in Alpine with
 * a renderless save, so a page's polling or a weak connection can't reopen it.
 */
new class extends Component
{
    #[Locked]
    public string $guide;

    /** False where it must wait to be asked for: a finished step, a preview. */
    #[Locked]
    public bool $auto = true;

    #[Locked]
    public bool $opensByItself = false;

    public function mount(): void
    {
        $this->opensByItself = $this->auto && ! auth()->user()->hasReadGuide($this->guide);
    }

    #[Renderless]
    public function markRead(): void
    {
        auth()->user()->markGuideRead($this->guide);
    }
};
?>

@php $content = UsageGuides::find($guide); @endphp

<div
    wire:ignore.self
    x-data="{ open: @js($opensByItself && $content !== null), confirmRead() { this.open = false; this.$wire.markRead() } }"
    x-on:usage-guide-open.window="open = true"
>
    @if ($content)
        {{-- On the body: a page's sticky or animated wrapper would otherwise trap the fixed sheet. --}}
        <template x-teleport="body">
            <x-bottom-sheet show="open" :label="$content['title']">
                <div dir="rtl" class="font-fa flex flex-col gap-4 text-start">
                    <h2 class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $content['title'] }}</h2>

                    <ol class="space-y-2.5 text-sm leading-7 text-ink dark:text-ink-dark">
                        @foreach ($content['lines'] as $line)
                            <li wire:key="guide-line-{{ $loop->index }}" class="flex gap-2.5">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent-soft text-xs font-extrabold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $loop->iteration }}</span>
                                <span>{{ $line }}</span>
                            </li>
                        @endforeach
                    </ol>

                    @isset($content['more'])
                        <a href="{{ route($content['more']['route']) }}" wire:navigate x-on:click="open = false" class="text-sm font-bold text-accent-ink dark:text-accent-ink-dark">{{ $content['more']['label'] }} ›</a>
                    @endisset

                    <button type="button" x-on:click="confirmRead()" class="w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-center text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 dark:bg-accent-dark">خواندم</button>
                </div>
            </x-bottom-sheet>
        </template>
    @endif
</div>
