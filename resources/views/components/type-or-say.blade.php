{{--
    The answer box of a one-task lesson screen: the learner types, or taps
    the mic in its corner and says it — the take is turned into text in the
    same box by the component's $method (see DictatesAnswers) and then
    checked like a typed answer. One line under it says either.

    @param string $model The wire:model path, e.g. "examples.2".
    @param string $method The component method that turns the take into
        text, called with $param once the upload lands.
    @param int|string $param Which box this is, passed to $method.
    @param string|null $draftKey A localStorage key for x-draft.
    @param string|null $error A line under the box (e.g. couldn't hear).
    @param string $placeholder
    @param string|null $onInput Raw Alpine run on every keystroke.
    @param int $rows
--}}
@props(['model', 'method', 'param', 'draftKey' => null, 'error' => null, 'placeholder' => 'Type here…', 'onInput' => null, 'rows' => 3])

<div {{ $attributes->class('flex flex-col gap-2') }}>
    <div class="relative">
        <textarea
            wire:model="{{ $model }}"
            rows="{{ $rows }}"
            placeholder="{{ $placeholder }}"
            @if ($onInput) x-on:input="{{ $onInput }}" @endif
            @if ($draftKey) x-draft="{ key: '{{ $draftKey }}', field: '{{ $model }}' }" @endif
            class="w-full resize-none rounded-2xl border-2 border-line bg-surface px-4 py-3 pr-14 text-base leading-relaxed text-ink focus:border-accent focus:ring-0 dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:focus:border-accent-dark"
        ></textarea>
        <div class="absolute right-2 bottom-3">
            <x-voice-recorder compact field="dictation" :on-recorded="$method" :on-recorded-param="$param" file-name="answer.webm" />
        </div>
    </div>

    <div wire:loading wire:target="{{ $method }}" class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">Turning your voice into text…</div>

    @if ($error)
        <p class="text-sm text-danger-ink">{{ $error }}</p>
    @else
        <p class="text-center text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Type or tap the mic to say it</p>
    @endif
</div>
