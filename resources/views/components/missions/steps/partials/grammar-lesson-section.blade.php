{{--
    One lesson section's content — heading, intro body, and its blocks.
    Extracted (Epic D) so the exact same rendering can appear twice: once
    paginated (the first-time walkthrough, non-readOnly) and once as a
    full, non-paginated dump inside the "Show lesson again" toggle that's
    available in BOTH normal and readOnly review mode (see
    ⚡grammar-in-context.blade.php) — the same pattern Vocabulary
    Builder's "Show the story again" already uses.

    Layout rules (the "easy to read" redesign):
      - one idea per section: a short heading, at most one plain sentence
        of body, then examples — never a wall of text;
      - generous, even spacing between blocks (space-y-5), examples set
        in larger type than the explanation around them;
      - the same block order in every lesson: rule → formula → ✅/❌ →
        examples → common mistake.

    Every block below that has a "correct answer" to give away (pairs,
    rule_examples, mistake_fix) is tap-to-reveal rather than shown open —
    the learner guesses first, then taps to check themselves. Each block
    gets its own local `revealed` object so the two render contexts above
    never share state.

    Block types: rule · formula · do_dont · mistake_fix · pairs · examples
    · chips · rule_examples.

    @param array{heading?: string, body?: string, blocks?: array} $section
--}}
@php
    // Headings are authored as "A · Title" — the letter becomes a badge so
    // the title itself can be set larger and read on its own.
    $heading = $section['heading'] ?? '';
    $badge = null;
    if (preg_match('/^([A-Z])\s·\s(.+)$/u', $heading, $headingParts)) {
        [, $badge, $heading] = $headingParts;
    }

    // Colour per formula part. Cycles when a part names no tone of its own.
    $formulaTones = [
        'accent' => 'bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark',
        'success' => 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark',
        'neutral' => 'bg-surface text-ink ring-1 ring-line dark:bg-surface-dark dark:text-ink-dark dark:ring-line-dark',
    ];
    $formulaCycle = array_keys($formulaTones);
@endphp

<div class="space-y-5">
    @if ($heading !== '')
        <div class="flex items-center gap-3">
            @if ($badge)
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-semibold text-white dark:bg-accent-dark dark:text-surface-sunken-dark">{{ $badge }}</span>
            @endif
            <h3 class="font-display text-lg leading-snug font-semibold text-ink dark:text-ink-dark">{{ $heading }}</h3>
        </div>
    @endif

    @if (! empty($section['body']))
        {{-- Trusted, developer-authored seed content — never learner input —
             so a light <strong>/<em> markup is allowed through untouched. --}}
        <p class="text-base leading-relaxed text-ink-soft dark:text-ink-soft-dark">{!! $section['body'] !!}</p>
    @endif

    @foreach ($section['blocks'] ?? [] as $block)
        @switch($block['type'] ?? null)
            @case('rule')
                {{-- The one-line takeaway of the section. The optional `fa`
                     line is a Persian explanation, folded away until asked
                     for so it never crowds the English. --}}
                <div x-data="{ showFa: false }" class="rounded-2xl border border-accent/30 bg-accent-soft p-4 dark:border-accent-dark/40 dark:bg-accent-soft-dark">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 shrink-0 text-accent-ink dark:text-accent-ink-dark">@svg('heroicon-o-light-bulb', 'h-6 w-6')</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-medium tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">The rule</p>
                            <p class="mt-1 text-lg leading-snug font-normal text-ink dark:text-ink-dark">{!! $block['text'] !!}</p>

                            @if (! empty($block['fa']))
                                <button
                                    type="button"
                                    x-on:click="showFa = !showFa"
                                    class="mt-3 inline-flex cursor-pointer items-center gap-1 rounded-full border border-accent/30 px-3 py-1 text-xs font-medium text-accent-ink dark:border-accent-dark/40 dark:text-accent-ink-dark"
                                >
                                    <span x-show="!showFa">فارسی</span>
                                    <span x-show="showFa" x-cloak>Hide</span>
                                </button>
                                <p x-show="showFa" x-cloak dir="rtl" class="mt-2 text-start text-base leading-loose text-ink-soft dark:text-ink-soft-dark">{!! $block['fa'] !!}</p>
                            @endif
                        </div>
                    </div>
                </div>
                @break

            @case('formula')
                {{-- A sentence pattern as colour-coded building blocks, each
                     with a tiny caption: [She] + [wakes] + [up early]. --}}
                <div>
                    @if (! empty($block['label']))
                        <p class="mb-2 text-xs font-medium tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $block['label'] }}</p>
                    @endif
                    <div class="flex flex-wrap items-start gap-x-2 gap-y-3">
                        @foreach ($block['parts'] ?? [] as $i => $part)
                            @php $tone = $formulaTones[$part['tone'] ?? $formulaCycle[$i % count($formulaCycle)]] ?? $formulaTones['neutral']; @endphp
                            <div class="flex flex-col items-center gap-1">
                                <span class="rounded-xl px-3.5 py-2 text-lg font-medium {{ $tone }}">{{ $part['text'] }}</span>
                                @if (! empty($part['caption']))
                                    <span class="text-xs font-normal text-ink-faint dark:text-ink-faint-dark">{{ $part['caption'] }}</span>
                                @endif
                            </div>
                            @if (! $loop->last)
                                <span class="pt-2 text-lg font-normal text-ink-faint dark:text-ink-faint-dark">+</span>
                            @endif
                        @endforeach
                    </div>
                </div>
                @break

            @case('do_dont')
                {{-- ❌ / ✅ side by side, always visible — the contrast IS the lesson. --}}
                <div class="space-y-3">
                    @foreach ($block['items'] ?? [] as $item)
                        <div class="space-y-1.5">
                            <p class="flex items-start gap-2 rounded-xl border border-danger-line bg-danger-soft px-3.5 py-2.5 text-base text-danger-ink">
                                <span class="mt-0.5 shrink-0">@svg('heroicon-s-x-circle', 'h-5 w-5')</span>
                                <span class="line-through decoration-danger/60">{{ $item['wrong'] }}</span>
                            </p>
                            <p class="flex items-start gap-2 rounded-xl border border-success/40 bg-success-soft px-3.5 py-2.5 text-base font-normal text-success dark:border-success-dark/40 dark:bg-success-soft-dark dark:text-success-dark">
                                <span class="mt-0.5 shrink-0">@svg('heroicon-s-check-circle', 'h-5 w-5')</span>
                                <span>{!! isset($item['highlight']) ? $this->highlightWord($item['right'], $item['highlight']) : e($item['right']) !!}</span>
                            </p>
                            @if (! empty($item['note']))
                                <p class="ps-1 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $item['note'] }}</p>
                            @endif
                        </div>
                    @endforeach
                </div>
                @break

            @case('mistake_fix')
                {{-- A short character-driven "spot the mistake" moment — the
                     scenario framing this epic adds ahead of the drier rule
                     blocks below. --}}
                <div x-data="{ revealed: false }" class="rounded-2xl border border-warning-line bg-warning-soft p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
                    <p class="inline-flex items-center gap-1.5 text-xs font-medium tracking-wide text-warning-ink uppercase dark:text-ink-soft-dark">
                        @svg('heroicon-o-exclamation-triangle', 'h-4 w-4') Spot the mistake
                    </p>
                    <p class="mt-2 text-sm font-normal text-ink-soft dark:text-ink-soft-dark">{{ $block['character'] ?? 'Someone' }} said:</p>
                    <p class="mt-1 text-lg font-normal text-ink dark:text-ink-dark">&ldquo;{{ $block['wrong'] }}&rdquo;</p>

                    <button
                        type="button"
                        x-show="!revealed"
                        x-on:click="revealed = true"
                        class="mt-3 inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-warning-line bg-surface px-4 py-1.5 text-sm font-medium text-ink-soft hover:bg-surface-sunken dark:border-line-dark dark:bg-surface-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >@svg('heroicon-o-magnifying-glass', 'h-4 w-4') What's the mistake?</button>

                    <div x-show="revealed" x-cloak class="mt-3 space-y-1.5">
                        <p class="flex items-start gap-2 text-lg font-normal text-success dark:text-success-dark">
                            <span class="mt-1 shrink-0">@svg('heroicon-s-check-circle', 'h-5 w-5')</span>
                            <span>&ldquo;{{ $block['right'] }}&rdquo;</span>
                        </p>
                        @if (! empty($block['explanation']))
                            <p class="ps-7 text-sm text-ink-soft dark:text-ink-soft-dark">{{ $block['explanation'] }}</p>
                        @endif
                    </div>
                </div>
                @break

            @case('pairs')
                {{-- Transformation examples — the right side is tap-to-reveal,
                     so the learner guesses the transformation first. --}}
                <div x-data="{ revealed: {} }" class="space-y-3">
                    @foreach ($block['pairs'] ?? [] as $i => $pair)
                        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                            <p class="text-lg font-normal text-ink dark:text-ink-dark">{{ $pair['left'] }}</p>
                            <div class="my-1.5 text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-arrow-down', 'h-4 w-4')</div>
                            <button
                                type="button"
                                x-on:click="revealed[{{ $i }}] = !revealed[{{ $i }}]"
                                class="block w-full cursor-pointer rounded-xl px-3.5 py-2.5 text-start text-base font-normal transition-colors"
                                :class="revealed[{{ $i }}] ? 'bg-success-soft text-success dark:bg-success-soft-dark dark:text-success-dark' : 'border border-dashed border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark'"
                            >
                                <span x-show="!revealed[{{ $i }}]">Tap to reveal</span>
                                <span x-show="revealed[{{ $i }}]" x-cloak>{{ $pair['right'] }}</span>
                            </button>
                        </div>
                    @endforeach
                </div>
                @break

            @case('examples')
                {{-- Standalone example sentences, each in its own card,
                     optionally grouped under a label (e.g. one per tense). --}}
                <div class="space-y-3">
                    @foreach ($block['groups'] ?? [] as $group)
                        @if (! empty($group['label']))
                            <p class="text-xs font-medium tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $group['label'] }}</p>
                        @endif
                        <div class="space-y-2">
                            @foreach ($group['items'] ?? [] as $item)
                                <p class="rounded-xl border border-line bg-surface px-4 py-3 text-base font-normal text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">{{ $item }}</p>
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @break

            @case('chips')
                {{-- A horizontal scale of words (e.g. a frequency scale, or a set
                     of time expressions), optionally grouped under a label. --}}
                <div class="space-y-3">
                    @foreach ($block['groups'] ?? [] as $group)
                        @if (! empty($group['label']))
                            <p class="text-xs font-medium tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $group['label'] }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 text-sm font-medium">
                            @foreach ($group['words'] ?? [] as $word)
                                <span class="rounded-full border border-line bg-surface px-3 py-1 text-ink dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark">{{ $word }}</span>
                                @if (! $loop->last) <span class="text-ink-faint dark:text-ink-faint-dark">@svg('heroicon-o-chevron-right', 'inline h-3.5 w-3.5')</span> @endif
                            @endforeach
                        </div>
                    @endforeach
                </div>
                @break

            @case('rule_examples')
                {{-- A rule paired with an example sentence — the example is
                     tap-to-reveal, so the learner tries to build it from the
                     rule first. --}}
                <div x-data="{ revealed: {} }" class="space-y-3">
                    @foreach ($block['items'] ?? [] as $i => $rule)
                        <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                            <p class="text-base font-normal text-ink dark:text-ink-dark">{{ $rule['rule'] }}</p>
                            <button
                                type="button"
                                x-on:click="revealed[{{ $i }}] = !revealed[{{ $i }}]"
                                class="mt-2 block w-full cursor-pointer rounded-xl px-3.5 py-2.5 text-start text-base font-normal transition-colors"
                                :class="revealed[{{ $i }}] ? 'bg-success-soft text-ink dark:bg-success-soft-dark dark:text-ink-dark' : 'border border-dashed border-line text-ink-soft dark:border-line-dark dark:text-ink-soft-dark'"
                            >
                                <span x-show="!revealed[{{ $i }}]">Tap for an example</span>
                                <span x-show="revealed[{{ $i }}]" x-cloak>{!! $this->highlightWord($rule['example'], $rule['highlight'] ?? '') !!}</span>
                            </button>
                        </div>
                    @endforeach
                </div>
                @break
        @endswitch
    @endforeach
</div>
