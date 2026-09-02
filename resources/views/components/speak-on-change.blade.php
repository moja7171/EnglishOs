{{--
    Reads $text aloud (window.eosVoice, SpeechSynthesis — see resources/js/app.js)
    exactly once per distinct $changeKey, e.g. wire:key="speak-{{ $round }}" one
    level up so a fresh element (and so a fresh x-init) only mounts when the
    round actually advances, not on every unrelated Livewire re-render.

    @param string|null $text The question/prompt to speak.
    @param string|int $changeKey Changes only when a genuinely new question appears.
--}}
@props(['text', 'changeKey'])

<span
    wire:key="speak-{{ $changeKey }}"
    x-data
    x-init="$nextTick(() => window.eosVoice?.speak($el.dataset.text))"
    data-text="{{ $text }}"
    class="hidden"
></span>
