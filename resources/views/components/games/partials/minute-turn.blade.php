{{--
    A Just a Minute turn. The talker sees the topic and the four words
    (ticked as the listener hears them) and can stop early; the listener
    taps each word they hear. Then the listener asks one question and taps
    "I asked". Needs the arena's $pairGame, $turn, $card, $explainer,
    $isExplainer, $friend.
--}}
@php
    $heard = $turn['current']['heard'] ?? [];
    $asking = ($turn['current']['phase'] ?? null) === App\Models\PairGame::PHASE_ASK;
@endphp

<div @class(['flex items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-extrabold tracking-wide', 'bg-accent-dark text-white' => $isExplainer && ! $asking, 'bg-surface-dark' => ! $isExplainer || $asking])>
    @svg($asking ? 'heroicon-o-question-mark-circle' : ($isExplainer ? 'heroicon-o-microphone' : 'heroicon-o-speaker-wave'), 'h-5 w-5')
    {{ match (true) {
        $asking && $isExplainer => $friend->name.' asks you one question — answer it',
        $asking => 'ASK '.$explainer->name.' one question about it',
        $isExplainer => 'YOUR TURN — talk! Use the words',
        default => 'LISTEN — tap each word you hear',
    } }}
</div>

<div class="space-y-4 rounded-3xl bg-surface p-5 text-ink shadow-[0_6px_0_rgba(0,0,0,0.4)]">
    <p class="text-[11px] font-extrabold tracking-wider text-ink-faint uppercase">{{ $card['source'] ?? '' }}</p>
    <p class="text-center font-display text-3xl leading-tight font-extrabold">{{ $card['word'] ?? '' }}</p>
    <div class="grid grid-cols-2 gap-2">
        @foreach ($card['words'] ?? [] as $word)
            @php $isHeard = in_array($word, $heard, true); @endphp
            @if (! $isExplainer && ! $asking && ! $isHeard)
                <button type="button" wire:key="hear-{{ $loop->index }}" wire:click="hear(@js($word))" wire:loading.attr="disabled" wire:target="hear" class="min-h-12 cursor-pointer rounded-xl border-2 border-line px-2 py-2 text-base font-extrabold text-ink active:scale-95 disabled:opacity-60">{{ $word }}</button>
            @else
                <span wire:key="word-{{ $loop->index }}" @class(['flex min-h-12 items-center justify-center gap-1 rounded-xl px-2 py-2 text-center text-base font-extrabold', 'animate-badge-pop bg-success text-white' => $isHeard, 'bg-surface-sunken text-ink-soft' => ! $isHeard])>
                    @if ($isHeard) @svg('heroicon-s-check', 'h-4 w-4 shrink-0') @endif {{ $word }}
                </span>
            @endif
        @endforeach
    </div>
</div>

<div class="mt-auto space-y-2.5">
    @if ($asking && ! $isExplainer)
        <div class="flex flex-wrap gap-1.5">
            @foreach (['Why…?', 'When…?', 'How did you feel?', 'What was the best part?'] as $starter)
                <span class="rounded-lg bg-surface-dark px-2.5 py-1.5 text-sm font-bold">{{ $starter }}</span>
            @endforeach
        </div>
        <button type="button" wire:click="asked" wire:loading.attr="disabled" wire:target="asked" class="flex h-16 w-full cursor-pointer items-center justify-center gap-2 rounded-2xl bg-success-dark text-lg font-extrabold text-white shadow-[0_4px_0_rgba(0,0,0,0.35)] disabled:opacity-60">
            @svg('heroicon-s-check', 'h-6 w-6') I asked — next turn
        </button>
    @elseif ($isExplainer && ! $asking)
        <button type="button" wire:click="doneTalking" wire:loading.attr="disabled" wire:target="doneTalking" class="h-12 w-full cursor-pointer rounded-2xl border-2 border-line-dark text-sm font-extrabold disabled:opacity-60">I’m done</button>
    @elseif (! $isExplainer)
        <p class="text-center text-sm font-semibold text-ink-soft-dark">Then ask {{ $explainer->name }} one question.</p>
    @endif
</div>
