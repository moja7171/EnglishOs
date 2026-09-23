{{--
    One lesson section's content — heading, intro body, and its blocks.
    Extracted (Epic D) so the exact same rendering can appear twice: once
    paginated (the first-time walkthrough, non-readOnly) and once as a
    full, non-paginated dump inside the "Show lesson again" toggle that's
    available in BOTH normal and readOnly review mode (see
    ⚡grammar-in-context.blade.php) — the same pattern Vocabulary
    Builder's "Show the story again" already uses.

    Every block below that has a "correct answer" to give away (pairs,
    rule_examples, mistake_fix) is tap-to-reveal rather than shown open —
    the learner guesses first, then taps to check themselves. Each block
    gets its own local `revealed` object so the two render contexts above
    never share state.

    @param array{heading?: string, body?: string, blocks?: array} $section
--}}
@if (! empty($section['heading']))
    <p class="text-sm font-bold">{{ $section['heading'] }}</p>
@endif

@if (! empty($section['body']))
    {{-- Trusted, developer-authored seed content — never learner input —
         so a light <strong>/<em> markup is allowed through untouched. --}}
    <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">{!! $section['body'] !!}</p>
@endif

@foreach ($section['blocks'] ?? [] as $block)
    @switch($block['type'] ?? null)
        @case('mistake_fix')
            {{-- A short character-driven "spot the mistake" moment — the
                 scenario framing this epic adds ahead of the drier rule
                 blocks below. --}}
            <div x-data="{ revealed: false }" class="mt-3 rounded-xl border border-line bg-surface-sunken p-3 dark:border-line-dark dark:bg-surface-sunken-dark">
                <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $block['character'] ?? 'Someone' }} said:</p>
                <p class="mt-1 text-sm font-semibold text-ink dark:text-ink-dark">&ldquo;{{ $block['wrong'] }}&rdquo;</p>

                <button
                    type="button"
                    x-show="!revealed"
                    x-on:click="revealed = true"
                    class="mt-2 inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft hover:bg-surface dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-dark"
                >@svg('heroicon-o-magnifying-glass', 'h-3 w-3') What's the mistake?</button>

                <div x-show="revealed" x-cloak class="mt-2 space-y-1">
                    <p class="text-sm font-semibold text-success dark:text-success-dark">&ldquo;{{ $block['right'] }}&rdquo;</p>
                    @if (! empty($block['explanation']))
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $block['explanation'] }}</p>
                    @endif
                </div>
            </div>
            @break

        @case('pairs')
            {{-- Two-column transformation examples — the right side is
                 tap-to-reveal, so the learner guesses the transformation
                 first. --}}
            <div x-data="{ revealed: {} }" class="mt-3 space-y-2">
                @foreach ($block['pairs'] ?? [] as $i => $pair)
                    <div class="grid grid-cols-2 gap-2 rounded-lg border border-line p-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                        <p>{{ $pair['left'] }}</p>
                        <button
                            type="button"
                            x-on:click="revealed[{{ $i }}] = !revealed[{{ $i }}]"
                            class="cursor-pointer text-left font-semibold"
                        >
                            <span x-show="!revealed[{{ $i }}]" class="text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark">Tap to reveal</span>
                            <span x-show="revealed[{{ $i }}]" x-cloak>{{ $pair['right'] }}</span>
                        </button>
                    </div>
                @endforeach
            </div>
            @break

        @case('examples')
            {{-- Standalone example sentences, each in its own box, optionally
                 grouped under a label (e.g. one group per tense). --}}
            <div class="mt-3 space-y-2 text-sm">
                @foreach ($block['groups'] ?? [] as $group)
                    @if (! empty($group['label']))
                        <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">{{ $group['label'] }}</p>
                    @endif
                    @foreach ($group['items'] ?? [] as $item)
                        <p class="rounded-lg border border-line p-2 text-ink dark:border-line-dark dark:text-ink-dark">{{ $item }}</p>
                    @endforeach
                @endforeach
            </div>
            @break

        @case('chips')
            {{-- A horizontal scale of words (e.g. a frequency scale, or a set
                 of time expressions), optionally grouped under a label. --}}
            @foreach ($block['groups'] ?? [] as $group)
                @if (! empty($group['label']))
                    <p class="text-xs font-semibold text-ink-faint dark:text-ink-faint-dark {{ ! $loop->first ? 'mt-2' : '' }}">{{ $group['label'] }}</p>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                    @foreach ($group['words'] ?? [] as $word)
                        <span class="rounded-full border border-line px-2 py-0.5 dark:border-line-dark">{{ $word }}</span>
                        @if (! $loop->last) <span class="text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-chevron-right', 'inline h-3 w-3')</span> @endif
                    @endforeach
                </div>
            @endforeach
            @break

        @case('rule_examples')
            {{-- A rule paired with an example sentence — the example is
                 tap-to-reveal, so the learner tries to build it from the
                 rule first. --}}
            <div x-data="{ revealed: {} }" class="mt-3 space-y-2">
                @foreach ($block['items'] ?? [] as $i => $rule)
                    <div class="rounded-lg border border-line p-2 text-sm text-ink dark:border-line-dark dark:text-ink-dark">
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $rule['rule'] }}</p>
                        <button type="button" x-on:click="revealed[{{ $i }}] = !revealed[{{ $i }}]" class="mt-0.5 cursor-pointer text-left font-semibold">
                            <span x-show="!revealed[{{ $i }}]" class="text-ink-faint underline decoration-dotted underline-offset-2 dark:text-ink-faint-dark">Tap for an example</span>
                            <span x-show="revealed[{{ $i }}]" x-cloak>{!! $this->highlightWord($rule['example'], $rule['highlight'] ?? '') !!}</span>
                        </button>
                    </div>
                @endforeach
            </div>
            @break
    @endswitch
@endforeach
