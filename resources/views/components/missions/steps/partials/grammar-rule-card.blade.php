{{--
    One lesson section as a short rule screen (lesson design 5): the
    section's name, its one-line rule (Persian behind a tap), the formula as
    colour-coded tiles and any word scale. Examples are left out — the
    formula carries one, and the "Which one is right?" screen after it is
    the section's example in action. Also lists every rule in the 💡 Rule
    sheet.

    @param array{heading?: string, blocks?: array} $section
--}}
@php
    $heading = preg_replace('/^[A-Z]\s·\s/u', '', $section['heading'] ?? '');
    $blocks = collect($section['blocks'] ?? []);
    $formulaTones = [
        'accent' => 'bg-accent text-white dark:bg-accent-dark',
        'success' => 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark',
        'neutral' => 'bg-surface text-ink dark:bg-surface-dark dark:text-ink-dark',
    ];
@endphp

<div class="flex flex-col gap-4">
    @if ($heading !== '')
        <span class="inline-flex w-fit rounded-full bg-accent-soft px-3 py-1 text-xs font-extrabold tracking-wide text-accent-ink uppercase dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $heading }}</span>
    @endif

    @if (! empty($section['body']))
        <p class="text-base leading-relaxed text-ink-soft dark:text-ink-soft-dark">{!! $section['body'] !!}</p>
    @endif

    @foreach ($blocks->where('type', 'rule') as $rule)
        {{-- Trusted, developer-authored seed content, light <strong>/<em> allowed. --}}
        <div x-data="{ showFa: false }" class="flex flex-col gap-2 rounded-3xl bg-accent-soft p-4 dark:bg-accent-soft-dark">
            <p class="text-lg leading-snug font-semibold text-ink dark:text-ink-dark [&_strong]:text-accent-ink dark:[&_strong]:text-accent-ink-dark">{!! $rule['text'] !!}</p>
            @if (! empty($rule['fa']))
                <button type="button" x-on:click="showFa = ! showFa" class="font-fa w-fit cursor-pointer rounded-full border border-accent/30 px-3 py-0.5 text-xs font-bold text-accent-ink dark:border-accent-dark/40 dark:text-accent-ink-dark">فارسی</button>
                <p x-show="showFa" x-cloak dir="rtl" class="font-fa text-base leading-loose text-ink-soft dark:text-ink-soft-dark">{!! $rule['fa'] !!}</p>
            @endif
        </div>
    @endforeach

    @foreach ($blocks->where('type', 'formula') as $formula)
        @if (! empty($formula['label']))
            <p class="-mb-2 text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $formula['label'] }}</p>
        @endif
        <div class="flex flex-wrap items-start gap-1.5">
            @foreach ($formula['parts'] ?? [] as $i => $part)
                <span class="flex flex-col items-center gap-0.5 rounded-xl px-3 py-2 text-base font-extrabold {{ $formulaTones[$part['tone'] ?? 'neutral'] ?? $formulaTones['neutral'] }}">
                    {{ $part['text'] }}
                    @if (! empty($part['caption']))
                        <small class="text-[10px] font-bold opacity-70">{{ $part['caption'] }}</small>
                    @endif
                </span>
            @endforeach
        </div>
    @endforeach

    @foreach ($blocks->where('type', 'chips') as $chips)
        @foreach ($chips['groups'] ?? [] as $group)
            <div class="flex flex-col gap-1.5">
                @if (! empty($group['label']))
                    <p class="text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $group['label'] }}</p>
                @endif
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($group['words'] ?? [] as $word)
                        <span class="rounded-full bg-surface px-3 py-1 text-sm font-bold text-ink dark:bg-surface-dark dark:text-ink-dark">{{ $word }}</span>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endforeach
</div>
