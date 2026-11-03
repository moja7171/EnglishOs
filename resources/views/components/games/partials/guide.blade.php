{{--
    "How to play" in three short Persian lines (PairGame::guide()). Needs
    $gameKey; $dark for the game screen.
--}}
@php $lines = App\Models\PairGame::guide($gameKey); @endphp
@if ($lines !== [])
    <ol dir="rtl" class="font-fa space-y-1.5 text-start text-sm leading-relaxed {{ ($dark ?? false) ? 'text-ink-dark' : 'text-ink dark:text-ink-dark' }}">
        @foreach ($lines as $line)
            <li class="flex gap-2"><span class="shrink-0 font-extrabold {{ ($dark ?? false) ? 'text-accent-ink-dark' : 'text-accent-ink dark:text-accent-ink-dark' }}">{{ $loop->iteration }}.</span><span>{{ $line }}</span></li>
        @endforeach
    </ol>
@endif
