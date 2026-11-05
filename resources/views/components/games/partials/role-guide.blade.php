{{--
    The first time a learner plays a game, the waiting screen teaches their
    own part with one example (see PairGame::roleGuide()): each phone shows
    its owner's part. «خواندم» keeps it closed from then on; ⓘ still opens
    the short guide. Needs a component with readRoleGuide(), and $pairGame, $me, $friend.
--}}
@php
    $roleGuide = App\Models\PairGame::roleGuide($pairGame->game);
    $part = $roleGuide === null ? null : ($roleGuide['both'] ?? $roleGuide[$pairGame->started_by_id === $me->id ? 'first' : 'second']);
@endphp

@if ($part && ! $me->hasReadGuide(App\Services\UsageGuides::keyForGame($pairGame->game)))
    <section dir="rtl" class="font-fa overflow-hidden rounded-2xl border border-line bg-surface text-start dark:border-line-dark dark:bg-surface-dark" aria-label="How to play">
        <h2 class="bg-accent-soft px-4 py-3 text-[15px] font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $part['title'] }}</h2>
        <div class="space-y-3 p-4 text-sm leading-7 text-ink dark:text-ink-dark">
            <p class="flex gap-2"><span class="min-w-11 shrink-0 font-bold">تو</span><span>{{ $part['you'] }}</span></p>
            <p class="flex gap-2"><span class="min-w-11 shrink-0 truncate font-bold">{{ $friend->name }}</span><span>{{ $part['friend'] }}</span></p>
            <div dir="ltr" class="rounded-xl bg-surface-sunken p-3 text-left font-sans dark:bg-surface-sunken-dark">
                <p class="text-xs font-bold text-ink-soft dark:text-ink-soft-dark">{{ $roleGuide['example']['label'] }}</p>
                <p class="mt-1 text-[15px] font-bold">{{ $roleGuide['example']['line'] }}</p>
            </div>
            <p>{{ $roleGuide['points'] }}</p>
            <button type="button" wire:click="readRoleGuide" wire:loading.attr="disabled" wire:target="readRoleGuide" class="block w-full cursor-pointer rounded-full bg-accent px-4 py-3 text-base font-bold text-white disabled:opacity-50 dark:bg-accent-dark">خواندم</button>
        </div>
    </section>
@endif
