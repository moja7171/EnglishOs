{{--
    The learner's own choice for texts that are heard first: "Always show
    text" or blurred until tapped (kept in this browser, see the heardText
    store in resources/js/app.js). Hidden on a phone with no English voice,
    where every text is shown anyway.
--}}
<button
    type="button"
    x-data
    x-show="! $store.heardText.noVoice"
    x-on:click="$store.heardText.set(! $store.heardText.always)"
    x-text="$store.heardText.always ? 'Blur the text again' : 'Always show text'"
    {{ $attributes->class('cursor-pointer self-start text-xs font-bold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark') }}
></button>
