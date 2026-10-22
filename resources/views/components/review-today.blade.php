@props(['review', 'current' => false])

{{-- Today's Daily Review as the first line of the Today box's checklist
     (<x-today-row>), opening /review. The count is today's batch (see
     User::dailyReviewItems()), not the whole backlog, so it stays a
     finishable couple of minutes. It ticks itself once the batch is done. --}}
@php $remaining = $review['remaining']; @endphp
<x-today-row
    :href="route('review.index')"
    :title="$remaining > 0 ? 'Daily Review · '.$remaining.' '.Str::plural('item', $remaining) : 'Daily Review'"
    label="Daily Review"
    :minutes="$remaining > 0 ? $review['minutes'] : null"
    :done="$remaining === 0"
    :current="$current"
/>
