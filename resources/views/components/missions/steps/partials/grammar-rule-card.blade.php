{{--
    One lesson section as a short rule screen (lesson design 5): the
    section's name, its one-line rule with its Persian line under it, the formula as
    colour-coded tiles and any word scale. Examples are left out — the
    formula carries one, and the "Which one is right?" screen after it is
    the section's example in action. Also lists every rule in the 💡 Rule
    sheet.

    @param array{heading?: string, blocks?: array} $section
    @param bool $withExamples The Notebook's Grammar tab also lists the
        section's right/wrong pairs and example sentences, each with its
        Persian line when it has one, hidden by that tab's Persian switch
        (Alpine `fa`).
--}}
@php
    $withExamples ??= false;
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
        <div class="flex flex-col gap-2 rounded-3xl bg-accent-soft p-4 dark:bg-accent-soft-dark">
            <p class="text-lg leading-snug font-semibold text-ink dark:text-ink-dark [&_strong]:text-accent-ink dark:[&_strong]:text-accent-ink-dark">{!! $rule['text'] !!}</p>
            @if (! empty($rule['fa']))
                <div @if ($withExamples) x-show="fa" @endif>
                    <x-fa-text :text="$rule['fa']" :markup="true" class="border-t border-accent/20 pt-2 text-sm text-ink-soft dark:border-accent-dark/30 dark:text-ink-soft-dark" />
                </div>
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
    @if ($withExamples)
        @foreach ($blocks->where('type', 'do_dont') as $block)
            <div class="flex flex-col gap-2">
                @foreach ($block['items'] ?? [] as $item)
                    <div class="flex flex-col gap-1 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                        <p dir="ltr" lang="en" class="text-sm text-danger-ink line-through decoration-danger">{{ $item['wrong'] }}</p>
                        <p dir="ltr" lang="en" class="text-base font-bold text-ink dark:text-ink-dark">{{ $item['right'] }}</p>
                        @if (! empty($item['fa']))
                            <div x-show="fa"><x-fa-text :text="$item['fa']" class="text-sm text-ink-soft dark:text-ink-soft-dark" /></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @foreach ($blocks->where('type', 'pairs') as $block)
            <div class="flex flex-col gap-2">
                @foreach ($block['pairs'] ?? [] as $pair)
                    <div class="flex flex-col gap-1 rounded-2xl bg-surface p-3 dark:bg-surface-dark">
                        <p dir="ltr" lang="en" class="text-base font-semibold text-ink dark:text-ink-dark">{{ $pair['left'] }}</p>
                        <p dir="ltr" lang="en" class="text-base text-ink-soft dark:text-ink-soft-dark">{{ $pair['right'] }}</p>
                        @if (! empty($pair['fa']))
                            <div x-show="fa"><x-fa-text :text="$pair['fa']" class="text-sm text-ink-soft dark:text-ink-soft-dark" /></div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        @foreach ($blocks->where('type', 'examples') as $block)
            @foreach ($block['groups'] ?? [] as $group)
                <div class="flex flex-col gap-1.5">
                    @if (! empty($group['label']))
                        <p class="text-xs font-bold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $group['label'] }}</p>
                    @endif
                    @foreach ($group['items'] ?? [] as $example)
                        @php
                            $exampleText = is_array($example) ? $example['text'] : $example;
                            $exampleFa = is_array($example) ? ($example['fa'] ?? null) : null;
                        @endphp
                        <div class="flex flex-col gap-1 rounded-2xl bg-surface px-3 py-2.5 dark:bg-surface-dark">
                            <p dir="ltr" lang="en" class="text-base font-semibold text-ink dark:text-ink-dark">{{ $exampleText }}</p>
                            @if ($exampleFa)
                                <div x-show="fa"><x-fa-text :text="$exampleFa" class="text-sm text-ink-soft dark:text-ink-soft-dark" /></div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endforeach
    @endif
</div>
