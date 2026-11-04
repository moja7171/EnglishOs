{{--
    A Two Truths turn. The teller sees the topic and its hint, marks which
    of their three statements is the lie (only their phone knows it) and
    taps Done; the listener hears the three, may ask two questions and
    picks the lie. Needs the arena's $pairGame, $turn, $card, $explainer,
    $isExplainer, $friend.
--}}
@php
    $guessing = ($turn['current']['phase'] ?? null) === App\Models\PairGame::PHASE_GUESS;
    $lie = $turn['current']['lie'] ?? null;
@endphp

<div @class(['flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-extrabold tracking-wide', 'bg-accent-dark text-white' => $isExplainer && ! $guessing, 'bg-surface-dark' => ! $isExplainer || $guessing])>
    @svg($guessing ? 'heroicon-o-question-mark-circle' : ($isExplainer ? 'heroicon-o-microphone' : 'heroicon-o-speaker-wave'), 'h-5 w-5')
    {{ match (true) {
        $guessing && $isExplainer => $friend->name.' asks — answer, keep your lie safe',
        $guessing => 'ASK up to 2 questions — then pick the lie',
        $isExplainer => 'YOUR TURN — say 3 things, one is a lie',
        default => 'LISTEN — '.$explainer->name.' says 3 things',
    } }}
</div>

<div class="space-y-4 rounded-3xl bg-surface p-5 text-ink shadow-[0_6px_0_rgba(0,0,0,0.4)]">
    <div class="text-center">
        <p class="text-[11px] font-extrabold tracking-wider text-ink-faint uppercase">Topic</p>
        <p class="font-display text-3xl leading-tight font-extrabold">{{ $card['word'] ?? '' }}</p>
        @if (filled($card['hint'] ?? null))
            <p class="mt-1 text-sm font-semibold text-ink-soft">{{ $card['hint'] }}</p>
        @endif
    </div>

    @if ($isExplainer)
        <div class="border-t border-line pt-3">
            <p class="text-[11px] font-extrabold tracking-wider text-ink-faint">{{ $guessing ? 'YOUR LIE' : 'WHICH ONE IS YOUR LIE?' }}</p>
            <div class="mt-1.5 grid grid-cols-3 gap-2">
                @foreach (range(1, App\Models\PairGame::STATEMENTS) as $statement)
                    @if ($guessing)
                        <span wire:key="lie-{{ $statement }}" @class(['flex h-12 items-center justify-center rounded-xl text-lg font-extrabold', 'bg-accent text-white' => $lie === $statement, 'bg-surface-sunken text-ink-faint' => $lie !== $statement])>{{ $statement }}</span>
                    @else
                        <button type="button" wire:key="lie-{{ $statement }}" wire:click="markLie({{ $statement }})" wire:loading.attr="disabled" wire:target="markLie,doneSaying" @class(['h-12 cursor-pointer rounded-xl border-2 text-lg font-extrabold', 'border-accent bg-accent text-white' => $lie === $statement, 'border-line text-ink' => $lie !== $statement])>{{ $statement }}</button>
                    @endif
                @endforeach
            </div>
            <p class="mt-2 text-center text-xs font-bold text-ink-faint">Say them as “One… Two… Three…” · 🤫 don’t show your screen</p>
        </div>
    @endif
</div>

<div class="mt-auto space-y-2.5">
    @if ($isExplainer && ! $guessing)
        <button type="button" wire:click="doneSaying" wire:loading.attr="disabled" wire:target="markLie,doneSaying" @disabled($lie === null) class="h-14 w-full cursor-pointer rounded-2xl bg-success-dark text-lg font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:cursor-default disabled:opacity-40">{{ $lie === null ? 'Mark your lie first' : 'Done — '.$friend->name.' guesses' }}</button>
    @elseif ($isExplainer)
        <p class="text-center text-sm font-semibold text-ink-soft-dark">{{ $friend->name }} is picking the lie…</p>
    @elseif ($guessing)
        <div class="flex flex-wrap gap-1.5">
            @foreach (['Did you really…?', 'When did you…?', 'Who with?', 'Why…?'] as $starter)
                <span class="rounded-lg bg-surface-dark px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
            @endforeach
        </div>
        <p class="text-xs font-extrabold tracking-widest text-ink-soft-dark">WHICH ONE IS THE LIE?</p>
        <div class="grid grid-cols-3 gap-2.5">
            @foreach (range(1, App\Models\PairGame::STATEMENTS) as $statement)
                <button type="button" wire:key="pick-{{ $statement }}" wire:click="pickLie({{ $statement }})" wire:loading.attr="disabled" wire:target="pickLie" class="h-16 cursor-pointer rounded-2xl bg-accent-dark text-2xl font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-60">{{ $statement }}</button>
            @endforeach
        </div>
    @else
        <div class="grid grid-cols-3 gap-2.5" aria-hidden="true">
            @foreach (range(1, App\Models\PairGame::STATEMENTS) as $statement)
                <span class="flex h-14 items-center justify-center rounded-2xl border-2 border-dashed border-line-dark text-xl font-extrabold text-ink-soft-dark">{{ $statement }}</span>
            @endforeach
        </div>
        <p class="text-center text-sm font-semibold text-ink-soft-dark">Then ask up to two questions and pick the lie.</p>
    @endif
</div>
