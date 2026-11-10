{{--
    A "Listen" pill that reads a whole passage or story aloud with the
    device voice (window.eosVoice, SpeechSynthesis — see resources/js/app.js),
    tapped again to stop. No mp3 needed; fails silently on a browser without
    speech support, same as <x-speak-button>.

    @param string $text The plain text to read.
    @param bool $follow Tell the page which sentence is being read: the button
        fires `eos-follow` ({ index }, -1 when it stops) so the text can light it.
--}}
@props(['text', 'follow' => false])

<button
    type="button"
    x-data="{
        playing: false,
        watcher: null,
        stop() {
            window.eosVoice?.stop();
            this.playing = false;
            clearInterval(this.watcher);
            @if ($follow) this.$dispatch('eos-follow', { index: -1 }); @endif
        },
        start() {
            this.playing = true;
            window.eosVoice?.speak(this.$el.dataset.text, { onEnd: () => this.stop()@if ($follow), onSentence: (index) => this.$dispatch('eos-follow', { index })@endif });
            {{-- Leaving the screen (Continue, Back) hides the button — stop reading then. --}}
            this.watcher = setInterval(() => { if (this.$el.offsetParent === null) this.stop() }, 500);
        },
    }"
    x-on:click="playing ? stop() : start()"
    data-text="{{ $text }}"
    {{ $attributes->class('inline-flex w-fit cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1 text-sm font-bold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark') }}
>
    <span x-show="! playing" class="inline-flex items-center gap-1.5">@svg('heroicon-o-speaker-wave', 'h-4 w-4') Listen</span>
    <span x-show="playing" x-cloak class="inline-flex items-center gap-1.5">@svg('heroicon-o-stop', 'h-4 w-4') Stop</span>
</button>
