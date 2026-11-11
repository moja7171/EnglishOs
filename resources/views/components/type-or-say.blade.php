{{--
    The answer box of a one-task lesson screen: the learner types, or taps
    the mic in its corner and says it — Stop sends the take, a clear line
    under the box says what is happening (sending, then turning it into
    text), the words land in the same box by the component's $method (see
    DictatesAnswers) and are then checked like a typed answer. One line
    under it says either. The box grows with a long answer.

    @param string $model The wire:model path, e.g. "examples.2".
    @param string $method The component method that turns the take into
        text, called with $param once the upload lands.
    @param int|string $param Which box this is, passed to $method.
    @param string|null $draftKey A localStorage key for x-draft.
    @param string|null $error A line under the box (e.g. couldn't hear).
    @param string $placeholder
    @param string|null $onInput Raw Alpine run on every keystroke.
    @param int $rows
    @param string|null $starter Words the sentence starts with, shown bold
        inside the box; the learner types only the rest.
--}}
@props(['model', 'method', 'param', 'draftKey' => null, 'error' => null, 'placeholder' => 'Type here…', 'onInput' => null, 'rows' => 3, 'starter' => null])

<div {{ $attributes->class('flex flex-col gap-2') }}>
    <div class="relative rounded-2xl border-2 border-line bg-surface focus-within:border-accent dark:border-line-dark dark:bg-surface-dark dark:focus-within:border-accent-dark">
        @if (filled($starter))
            <p class="px-4 pt-3 text-base font-extrabold text-ink dark:text-ink-dark">{{ $starter }} <span class="font-normal text-ink-faint dark:text-ink-faint-dark">…</span></p>
        @endif
        {{-- Grows with its text (a long spoken answer stays readable), up to 20rem, then scrolls. A box on a screen that is not showing has no height to measure: it keeps its rows and is measured again when the screen opens. --}}
        <textarea
            wire:model="{{ $model }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            x-data="{ fit() { this.$el.style.height = 'auto'; if (this.$el.scrollHeight > 0) { this.$el.style.height = Math.min(this.$el.scrollHeight, 320) + 'px' } } }"
            x-init="new ResizeObserver(() => { if ($el.offsetHeight > 0 && $el.style.height === 'auto') fit() }).observe($el)"
            x-effect="$wire.get('{{ $model }}'); $nextTick(() => fit())"
            x-on:input="fit(); {{ $onInput }}"
            @if ($draftKey) x-draft="{ key: '{{ $draftKey }}', field: '{{ $model }}' }" @endif
            class="block max-h-80 w-full resize-none overflow-y-auto rounded-2xl border-0 bg-transparent px-4 py-3 pr-14 text-base leading-relaxed text-ink focus:ring-0 focus:outline-none dark:text-ink-dark"
        ></textarea>
        <div class="absolute right-2 bottom-3">
            <x-voice-recorder compact field="dictation" :on-recorded="$method" :on-recorded-param="$param" file-name="answer.webm" />
        </div>
    </div>

    <p wire:loading.flex wire:target="dictation" class="flex items-center justify-center gap-2 rounded-2xl bg-surface-sunken px-4 py-3 text-sm font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Sending your voice…</p>
    <p wire:loading.flex wire:target="{{ $method }}" class="flex items-center justify-center gap-2 rounded-2xl bg-surface-sunken px-4 py-3 text-sm font-bold text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">@svg('heroicon-o-arrow-path', 'h-4 w-4 animate-spin') Turning your voice into text…</p>

    <div wire:loading.remove wire:target="dictation, {{ $method }}">
        @if ($error)
            <p class="text-sm text-danger-ink">{{ $error }}</p>
        @else
            <p class="text-center text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Type or tap the mic to say it</p>
        @endif
    </div>
</div>
