{{--
    A Who am I turn. The holder sees the secret and taps the answer to
    each question (Yes / No / Sometimes) and "got it" when the guess is
    right; the guesser sees only the answers so far, the question count
    and question starters (and the category, for beginners). Needs the
    arena's $pairGame, $turn, $card, $explainer, $isExplainer, $friend.
--}}
@php
    $answers = $turn['current']['answers'] ?? [];
    $asked = count($answers);
    $max = App\Models\PairGame::MAX_QUESTIONS;
    $answerStyles = [
        'yes' => 'bg-success dark:bg-success-dark text-white',
        'no' => 'bg-danger-soft text-danger-ink',
        'sometimes' => 'bg-surface dark:bg-surface-dark text-ink dark:text-ink-dark',
    ];
@endphp

@if ($isExplainer)
    <div class="-mb-4 flex items-center gap-2.5 rounded-t-2xl bg-accent dark:bg-accent-dark px-4 py-3 text-sm font-extrabold tracking-wide text-white">
        @svg('heroicon-o-lock-closed', 'h-5 w-5')
        YOU ARE THE SECRET — {{ $friend->name }} asks
    </div>

    <div class="space-y-3 rounded-b-2xl bg-surface dark:bg-surface-dark p-5 text-center text-ink dark:text-ink-dark border border-line dark:border-line-dark">
        <p class="text-[11px] font-extrabold tracking-wider text-ink-faint dark:text-ink-faint-dark uppercase">{{ $card['category'] ?? '' }}</p>
        <p class="font-display text-4xl leading-tight font-extrabold break-words">{{ $card['word'] ?? '' }}</p>
        <p class="text-xs font-bold text-ink-faint dark:text-ink-faint-dark">Answer only: Yes · No · Sometimes</p>
    </div>

    <p class="text-center text-sm font-extrabold text-ink-soft dark:text-ink-soft-dark">Question {{ min($asked + 1, $max) }} / {{ $max }}</p>

    <div class="mt-auto space-y-2.5">
        <div class="grid grid-cols-3 gap-2.5">
            @foreach (App\Models\PairGame::ANSWERS as $answer)
                <button type="button" wire:key="answer-{{ $asked }}-{{ $answer }}" wire:click="answer('{{ $answer }}', {{ $asked }})" wire:loading.attr="disabled" wire:target="answer,got" class="h-14 cursor-pointer rounded-2xl text-base font-extrabold capitalize disabled:opacity-60 {{ $answerStyles[$answer] }}">{{ $answer }}</button>
            @endforeach
        </div>
        <button type="button" wire:click="got(0)" wire:loading.attr="disabled" wire:target="answer,got" class="flex h-16 w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-success dark:bg-success-dark text-lg font-extrabold text-white dark:text-ground-dark shadow-sm disabled:opacity-60">
            @svg('heroicon-s-check', 'h-6 w-6') {{ $friend->name }} guessed it!
        </button>
    </div>
@else
    <div class="-mb-4 flex items-center gap-2.5 rounded-t-2xl bg-surface dark:bg-surface-dark px-4 py-3 text-sm font-extrabold tracking-wide">
        @svg('heroicon-o-question-mark-circle', 'h-5 w-5 text-ink-soft dark:text-ink-soft-dark')
        ASK — {{ $explainer->name }} holds the secret
    </div>

    <div class="flex h-44 flex-col items-center justify-center gap-2 rounded-b-2xl border-2 border-t-0 border-dashed border-line dark:border-line-dark text-center">
        @if ($asked > 0)
            <p wire:key="last-answer-{{ $asked }}" x-data x-init="navigator.vibrate?.(40)" class="animate-badge-pop font-display text-5xl font-extrabold uppercase {{ $answers[$asked - 1] === 'yes' ? 'text-success dark:text-success-dark' : 'text-ink dark:text-ink-dark' }}">{{ $answers[$asked - 1] }}</p>
        @else
            <span class="font-display text-6xl font-extrabold text-ink-soft dark:text-ink-soft-dark">?</span>
        @endif
        @if ($pairGame->rules['show_category'] ?? false)
            <p class="text-sm font-bold text-ink-soft dark:text-ink-soft-dark">It’s {{ $card['category'] ?? '' }}</p>
        @endif
    </div>

    <div class="flex items-center justify-between">
        <p class="text-xs font-extrabold tracking-widest text-ink-soft dark:text-ink-soft-dark">QUESTIONS {{ $asked }} / {{ $max }}</p>
        @if ($asked < App\Models\PairGame::QUICK_GUESS_QUESTIONS)
            <p class="text-xs font-bold text-accent-ink dark:text-accent-ink-dark">Guess in {{ App\Models\PairGame::QUICK_GUESS_QUESTIONS }}: +1 bonus</p>
        @endif
    </div>

    @if ($answers !== [])
        <div class="flex flex-wrap gap-1.5">
            @foreach ($answers as $index => $answer)
                <span wire:key="asked-{{ $index }}" class="rounded-full px-2.5 py-1 text-xs font-extrabold capitalize {{ $answerStyles[$answer] }}">{{ $index + 1 }} · {{ $answer }}</span>
            @endforeach
        </div>
    @endif

    <div class="mt-auto">
        <p class="text-[11px] font-extrabold tracking-wider text-ink-soft dark:text-ink-soft-dark">ASK</p>
        <div class="mt-1.5 flex flex-wrap gap-1.5">
            @foreach (['Am I a…?', 'Do I…?', 'Can I…?', 'Is it…?'] as $starter)
                <span class="rounded-lg bg-surface dark:bg-surface-dark px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
            @endforeach
        </div>
    </div>
@endif
