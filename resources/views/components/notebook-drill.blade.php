{{--
    One notebook line's practice card (NotebookDrill builds it, the
    PractisesNotebookLines trait answers it) — the same in Daily Review
    and the notebook page's practice. The caller puts it on its card and
    passes the trait's state in.

    @param \App\Models\NotebookEntry $entry
    @param int|null $answer  $drillAnswer
    @param bool|null $correct  $drillCorrect
    @param bool $typing  $drillTyping
    @param string|null $heard  $drillHeard
    @param bool $missedAudio  $drillMissedAudio
    @param mixed $recording  $drillRecording
--}}
@props(['entry', 'answer' => null, 'correct' => null, 'typing' => false, 'heard' => null, 'missedAudio' => false, 'recording' => null])

@php
    use App\Services\NotebookDrill;

    $card = NotebookDrill::card($entry);
    $answered = $correct !== null;
    $prompt = 'text-xs font-medium tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark';
    $link = 'inline-flex cursor-pointer items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink hover:underline dark:text-ink-faint-dark dark:hover:text-ink-dark';
@endphp

<div class="space-y-4">
    @if ($card['mode'] === 'spot')
        <p class="{{ $prompt }}">Tap the wrong word</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($card['words'] as $i => $word)
                @php $state = ! $answered ? 'idle' : (in_array($i, $card['wrong'], true) ? 'wrong' : ($answer === $i ? 'selected' : 'muted')); @endphp
                <button
                    type="button"
                    wire:key="drill-word-{{ $entry->id }}-{{ $i }}"
                    wire:click="drillTap({{ $i }})"
                    @disabled($answered)
                    data-state="{{ $state }}"
                    class="choice min-h-10! w-auto! px-3! {{ $state === 'wrong' ? 'line-through' : '' }}"
                >{{ $word }}</button>
            @endforeach
        </div>
    @elseif ($card['mode'] === 'pick')
        <p class="{{ $prompt }}">Fill the gap</p>
        <p class="text-lg leading-relaxed text-ink dark:text-ink-dark">
            {{ $card['before'] }}
            <span class="inline-block min-w-12 border-b-2 border-accent px-1 text-center font-semibold dark:border-accent-dark {{ $answered ? 'text-success dark:text-success-dark' : '' }}">{{ $answered ? $card['options'][$card['correct']] : "\u{00A0}" }}</span>
            {{ $card['after'] }}
        </p>
        <div class="flex flex-wrap gap-2">
            @foreach ($card['options'] as $i => $option)
                @php $state = ! $answered ? 'idle' : ($i === $card['correct'] ? 'correct' : ($answer === $i ? 'wrong' : 'muted')); @endphp
                <button
                    type="button"
                    wire:key="drill-chip-{{ $entry->id }}-{{ $i }}"
                    wire:click="drillTap({{ $i }})"
                    @disabled($answered)
                    data-state="{{ $state }}"
                    class="choice w-auto! rounded-full px-5"
                >{{ $option }}</button>
            @endforeach
        </div>
    @elseif ($card['mode'] === 'choose')
        {{-- The question is the big line (it names the tip), with the tip's own
             example under it as a reminder of what to look for. --}}
        <p class="lesson-prompt text-ink dark:text-ink-dark">{{ $card['question'] }}</p>
        @if ($techniqueExample = $entry->techniqueExample())
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">e.g. {{ $techniqueExample }}</p>
        @endif
        <div class="grid gap-2.5">
            @foreach ($card['options'] as $i => $option)
                @php $state = ! $answered ? 'idle' : ($i === $card['correct'] ? 'correct' : ($answer === $i ? 'wrong' : 'muted')); @endphp
                <button
                    type="button"
                    wire:key="drill-option-{{ $entry->id }}-{{ $i }}"
                    wire:click="drillTap({{ $i }})"
                    @disabled($answered)
                    data-state="{{ $state }}"
                    class="choice"
                >
                    <span class="choice-key">{{ chr(65 + $i) }}</span>
                    <span class="min-w-0 flex-1 leading-snug">{{ $option }}</span>
                    @if ($state === 'correct') <span class="shrink-0">@svg('heroicon-s-check-circle', 'h-5 w-5')</span> @endif
                    @if ($state === 'wrong') <span class="shrink-0">@svg('heroicon-s-x-circle', 'h-5 w-5')</span> @endif
                </button>
            @endforeach
        </div>
    @else
        @if ($entry->original)
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">
                You said: <span class="text-danger-ink line-through decoration-danger">{{ $entry->original }}</span>
            </p>
        @endif
        @if (! $answered)
            <p class="{{ $prompt }}">Say it right</p>
            @if ($missedAudio)
                <p class="text-xs font-semibold text-danger-ink">Couldn't hear that — try again.</p>
            @endif
            @if ($typing)
                <form wire:submit="drillCheckTyped" class="flex items-center gap-2">
                    <input
                        type="text"
                        wire:model="drillTyped"
                        aria-label="Write the sentence correctly"
                        placeholder="Write it correctly…"
                        class="w-full rounded-lg border border-line bg-transparent px-2 py-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                    >
                    <button
                        type="submit"
                        title="Check"
                        class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-accent text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
                    ><span class="sr-only">Check</span>@svg('heroicon-o-check', 'h-5 w-5')</button>
                </form>
            @else
                <x-voice-recorder field="drillRecording" :file="$recording" on-recorded="drillHeardRecording" file-name="notebook-say.webm" />
            @endif
            <div class="flex flex-wrap gap-4">
                @unless ($typing)
                    <button type="button" wire:click="$set('drillTyping', true)" class="{{ $link }}">@svg('heroicon-o-pencil', 'h-3.5 w-3.5') I'll type</button>
                @endunless
                <button type="button" wire:click="drillShowFix" class="{{ $link }}">@svg('heroicon-o-eye', 'h-3.5 w-3.5') Show the fix</button>
            </div>
        @elseif ($heard !== null && ! $correct)
            <p class="text-sm text-ink-soft dark:text-ink-soft-dark">{{ $typing ? 'You wrote' : 'You said' }}: {{ $heard }}</p>
        @endif
    @endif

    @if ($answered)
        <div class="space-y-1.5">
            @if ($correct)
                <p class="inline-flex items-center gap-1 text-sm font-semibold text-success dark:text-success-dark">@svg('heroicon-o-check-circle', 'h-4 w-4') Spot on!</p>
            @else
                <p class="inline-flex items-center gap-1 text-sm font-semibold text-danger-ink">@svg('heroicon-o-arrow-path', 'h-4 w-4') Not quite</p>
            @endif
            <p class="flex items-center gap-2 text-sm font-semibold text-success dark:text-success-dark">{{ $entry->better }} <x-speak-button :text="$entry->better" /></p>
            @unless ($entry->isMistake())
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Listen, then say it out loud.</p>
            @endunless
            @if ($entry->note)
                <p class="font-fa text-sm text-ink-soft dark:text-ink-soft-dark" dir="rtl">{{ $entry->note }}</p>
            @endif
        </div>
        <button
            type="button"
            wire:click="drillNext"
            title="Next"
            class="flex w-full cursor-pointer items-center justify-center rounded-full bg-accent px-5 py-3 text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
        ><span class="sr-only">Next</span>@svg('heroicon-o-arrow-right', 'h-5 w-5')</button>
    @endif
</div>
