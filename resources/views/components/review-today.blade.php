@props(['review'])

{{-- Today's Daily Review, shown inside the Today box as one quiet row that
     opens /review — same pattern as <x-listening-today>: not one of the
     numbered steps, never gates Continue. The count is today's batch (see
     User::dailyReviewItems()), not the whole backlog, so it stays a
     finishable "a couple of minutes". Once the batch is done the row
     stays, with a "Reviewed today" tick. --}}
@php
    $remaining = $review['remaining'];
    $breakdown = $review['breakdown'];
    $parts = collect([
        $breakdown['word'] ? $breakdown['word'].' '.Str::plural('word', $breakdown['word']) : null,
        $breakdown['grammar'] ? $breakdown['grammar'].' grammar' : null,
        $breakdown['speaking'] ? $breakdown['speaking'].' speaking' : null,
    ])->filter()->push('~'.max(1, (int) ceil($remaining / 4)).' min')->implode(' · ');
@endphp
<x-today-row
    :href="route('review.index')"
    :title="$remaining > 0 ? $remaining.' '.Str::plural('item', $remaining).' ready for Daily Review' : 'Daily Review'"
    label="Daily Review"
    :done="$remaining === 0"
    :hint="$remaining > 0 ? $parts : 'Reviewed today'"
/>
