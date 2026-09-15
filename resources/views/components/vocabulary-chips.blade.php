{{--
    Clickable vocabulary-word chips — tapping one drops the word into the
    sentence the learner is actually writing, instead of the word just
    sitting in inert text they have to retype themselves.

    Where the word lands, in order:
      1. the input the learner is typing in right now, at their cursor —
         the case that matters most, since a chip is usually tapped
         mid-sentence, and the old "always the next empty box" rule sent
         the word somewhere else entirely;
      2. otherwise the first empty box;
      3. otherwise nowhere. Never overwrite: tapping a chip once every box
         was full used to silently replace whatever the learner had
         written in box 1 (real data loss, reported from several steps).

    The value is written straight to the DOM input and an `input` event is
    dispatched, so wire:model picks it up client-side (no server
    round-trip just to insert a word — it used to take a full request per
    tap) and the step's own x-on:input tracking still fires.

    @param array $words The learner's selected vocabulary words.
    @param string $field The Livewire array property to fill, e.g. "sentences".
    @param string|null $onInsert Extra Alpine statements run right after the
        word is inserted — e.g. to update the caller's own `filled`/`dismissed`
        tracking arrays. `idx` is available as the index that was filled.
    @param array $titles Optional word => tooltip map (Listening shows each
        target phrase's meaning on hover).
--}}
@props(['words', 'field', 'onInsert' => null, 'titles' => []])

@if (count($words))
    <div
        class="flex flex-wrap gap-1.5"
        {{-- `component` is the Livewire root, NOT Alpine's $root: this
             wrapper has its own x-data, so $root would be the chip strip
             itself, which contains no inputs. `lastInput` is needed
             because tapping a chip blurs whatever the learner was typing
             in — without it, "drop it where I'm writing" always fell
             through to the first empty box instead. --}}
        x-data="{
            lastInput: null,
            component: null,
            selector: 'input[wire\\:model^={{ json_encode($field.'.') }}], textarea[wire\\:model^={{ json_encode($field.'.') }}]',
            targets() { return this.component ? [...this.component.querySelectorAll(this.selector)] : [] },
        }"
        x-init="
            component = $el.closest('[wire\\:id]');
            component?.addEventListener('focusin', e => { if (e.target.matches(selector)) lastInput = e.target });
        "
    >
        @foreach ($words as $word)
            <button
                type="button"
                @if (! empty($titles[$word])) title="{{ $titles[$word] }}" @endif
                x-on:click="
                    (() => {
                        const inputs = targets();
                        if (! inputs.length) return;

                        const recent = lastInput && inputs.includes(lastInput) ? lastInput : null;
                        // Mid-sentence beats everything; an empty box the
                        // learner merely visited earlier does not, or the
                        // first tap after finishing one sentence would go
                        // back to that box instead of starting the next.
                        let target = inputs.includes(document.activeElement) ? document.activeElement : null;
                        if (! target && recent && recent.value.trim() !== '') target = recent;
                        if (! target) target = inputs.find(i => i.value.trim() === '');
                        if (! target) target = recent ?? inputs[inputs.length - 1];

                        const idx = inputs.indexOf(target);
                        const value = target.value;
                        const at = target.selectionStart ?? value.length;
                        const before = value.slice(0, at);
                        const after = value.slice(at);
                        // Capitalised only when it genuinely starts the sentence.
                        const word = before.trim() === '' ? {{ json_encode(ucfirst($word)) }} : {{ json_encode($word) }};
                        const lead = before !== '' && ! before.endsWith(' ') ? ' ' : '';
                        const tail = after !== '' && ! after.startsWith(' ') ? ' ' : '';
                        const insert = lead + word + tail;

                        target.value = before + insert + after;
                        target.dispatchEvent(new Event('input', { bubbles: true }));
                        {{ $onInsert }}
                        target.focus();
                        const caret = (before + lead + word).length;
                        target.setSelectionRange(caret, caret);
                    })()
                "
                class="cursor-pointer rounded-full border border-line px-2.5 py-1 text-xs text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
            >{{ $word }}</button>
        @endforeach
    </div>
@endif
