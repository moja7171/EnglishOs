{{--
    One step of an Ask & Answer round. The speaker sees the Persian line
    (and a fainter "say more" line), records the English sentence and gets
    it checked on the spot; after a miss they repeat the corrected sentence.
    The other phone shows the English sentence blurred — a tap opens it — so
    the friend can help. Needs the arena's $pairGame, $turn, $card,
    $turnIndex, $me, $friend and the play page's $audioFile / $askError.
--}}
@php
    $phase = $pairGame->phase();
    $speaker = $pairGame->askSpeaker();
    $iSpeak = $speaker?->is($me) ?? false;
    $level = $pairGame->askLevel();
    $step = $pairGame->askStep();
    $isQuestion = $phase === App\Models\PairGame::PHASE_QUESTION;
    $missed = $step && $step['tries'] >= 1 && $step['status'] === null;
    $questionStep = $turn['current']['qa'][App\Models\PairGame::PHASE_QUESTION] ?? null;
    $myLevels = $pairGame->askLevels($me);
@endphp

@if ($level && $step)
    <div @class(['flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-extrabold tracking-wide', 'bg-accent dark:bg-accent-dark text-white' => $iSpeak, 'bg-surface dark:bg-surface-dark' => ! $iSpeak])>
        @svg($iSpeak ? 'heroicon-o-microphone' : 'heroicon-o-light-bulb', 'h-5 w-5')
        @if ($iSpeak)
            YOUR TURN — {{ $isQuestion ? 'ask '.$friend->name : 'answer '.$friend->name }}
        @else
            {{ strtoupper($speaker->name) }} {{ $isQuestion ? 'IS ASKING' : 'IS ANSWERING' }} — help them
        @endif
    </div>

    @if ($isQuestion && filled($card['context_fa'] ?? null))
        <p dir="rtl" class="rounded-2xl bg-surface-sunken px-4 py-3 text-center text-base font-bold text-ink dark:bg-surface-sunken-dark dark:text-ink-dark">{{ $card['context_fa'] }}</p>
    @endif

    @if (! $isQuestion && $questionStep)
        {{-- The question that was just asked, with how it went. --}}
        <p @class(['rounded-2xl px-4 py-2.5 text-center text-sm font-bold', 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' => in_array($questionStep['status'], ['ok', 'fixed'], true), 'bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark' => ! in_array($questionStep['status'], ['ok', 'fixed'], true)])>
            {{ $pairGame->explainer()->is($me) ? 'You' : $pairGame->explainer()->name }} asked:
            “{{ in_array($questionStep['status'], ['ok', 'fixed'], true) ? ($questionStep['heard'] ?? '') : ($questionStep['correct'] ?? $level['en']) }}”
        </p>
    @endif

    @if ($iSpeak)
        <div wire:key="ask-say-{{ $turnIndex }}-{{ $phase }}-{{ $step['tries'] }}" class="animate-review-card-in space-y-3 rounded-2xl border border-line bg-surface p-5 text-center text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">
            @if ($missed)
                <p class="text-xs font-extrabold tracking-wider text-warning-ink uppercase">Say it like this</p>
                <p class="font-display text-2xl leading-tight font-extrabold break-words" dir="ltr">{{ $step['correct'] }}</p>
                @if (filled($step['why']))
                    <p dir="rtl" class="rounded-xl bg-warning-soft px-3 py-2 text-sm font-semibold text-warning-ink">{{ $step['why'] }}</p>
                @endif
                <button type="button" x-on:click="window.eosVoice?.speak(@js($step['correct']))" class="cursor-pointer text-sm font-bold text-accent-ink dark:text-accent-ink-dark">🔊 Listen</button>
            @else
                <p class="text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">{{ $isQuestion ? 'Ask' : 'Answer' }} in English</p>
                <p dir="rtl" class="font-display text-2xl leading-snug font-extrabold">{{ $level['fa'] }}</p>
                @if (filled($level['fa_extra']))
                    <p dir="rtl" class="text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">✨ اگر خواستی، بعدش: {{ $level['fa_extra'] }}</p>
                @endif
            @endif
        </div>

        <div class="mt-auto space-y-3">
            <div wire:ignore wire:key="ask-rec-{{ $turnIndex }}-{{ $phase }}-{{ $step['tries'] }}-{{ $step['unchecked'] }}" class="flex flex-col items-center gap-2">
                <x-voice-recorder big field="audioFile" :file="$audioFile" on-recorded="submitAskAnswer" file-name="say.webm" />
            </div>
            <p wire:loading wire:target="submitAskAnswer" class="text-center text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">Checking…</p>
            @if ($askError)
                <p class="rounded-xl bg-warning-soft px-3 py-2 text-center text-sm font-semibold text-warning-ink">{{ $askError }}</p>
            @endif

            @if ($isQuestion)
                <div class="grid grid-cols-3 items-center gap-2 text-sm font-bold">
                    <button type="button" wire:click="setAskLevel('q', {{ max(1, $myLevels['q'] - 1) }})" @disabled($myLevels['q'] <= 1) class="h-11 cursor-pointer rounded-xl text-ink-soft disabled:cursor-default disabled:opacity-40 dark:text-ink-soft-dark">− Easier</button>
                    <span class="text-center text-xs font-extrabold text-ink-faint dark:text-ink-faint-dark">Level {{ $myLevels['q'] }}</span>
                    <button type="button" wire:click="setAskLevel('q', {{ min(3, $myLevels['q'] + 1) }})" @disabled($myLevels['q'] >= 3) class="h-11 cursor-pointer rounded-xl text-accent-ink disabled:cursor-default disabled:opacity-40 dark:text-accent-ink-dark">Harder +</button>
                </div>
            @else
                <p class="text-center text-xs font-extrabold text-ink-faint dark:text-ink-faint-dark">Level {{ $pairGame->askLevelNow() }}</p>
            @endif
            <button type="button" wire:click="passAsk" wire:loading.attr="disabled" wire:target="passAsk,submitAskAnswer" class="mx-auto block h-11 cursor-pointer text-sm font-bold text-ink-faint dark:text-ink-faint-dark">Skip</button>
        </div>
    @else
        <div wire:key="ask-help-{{ $turnIndex }}-{{ $phase }}-{{ $step['tries'] }}" x-data="{ open: false }" class="space-y-2">
            <p class="text-center text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark">{{ $speaker->name }} should say</p>
            <button type="button" x-on:click="open = ! open" class="block w-full cursor-pointer rounded-2xl border border-line bg-surface p-5 text-center dark:border-line-dark dark:bg-surface-dark">
                <span x-bind:class="open ? '' : 'blur-md select-none'" class="block font-display text-2xl leading-tight font-extrabold break-words text-ink transition dark:text-ink-dark" dir="ltr">{{ $missed ? $step['correct'] : $level['en'] }}</span>
                @if (filled($level['en_extra']) && ! $missed)
                    <span x-bind:class="open ? '' : 'blur-md select-none'" class="mt-2 block text-sm font-semibold text-ink-faint transition dark:text-ink-faint-dark" dir="ltr">✨ {{ $level['en_extra'] }}</span>
                @endif
                <span x-show="! open" class="mt-3 block text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Tap to check your friend</span>
            </button>
        </div>
        <div class="flex flex-1 flex-col items-center justify-center gap-3 text-center">
            <span class="h-6 w-6 animate-spin rounded-full border-2 border-accent border-t-transparent dark:border-accent-dark dark:border-t-transparent"></span>
            <p class="text-sm font-semibold text-ink-soft dark:text-ink-soft-dark">{{ $missed ? $speaker->name.' is repeating it…' : 'Listen to '.$speaker->name }}</p>
        </div>
    @endif
@endif
