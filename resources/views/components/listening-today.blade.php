@props(['listening'])

{{-- The daily listening habit, one muted line at the bottom of the Today
     box (<x-today-row>), opening /listening, where the picks and their
     transcripts live. Kept out of the day's order and its time: one pick,
     any time of day, never gates anything. Named apart from the steps' own
     "Listen Again" so the two are never taken for each other. --}}
<ul>
    <x-today-row
        :href="route('listening.show')"
        title="Extra listening · anytime"
        label="Extra listening"
        :done="$listening['listenedToday']"
        tick="markListened"
        tick-label="I listened"
        muted
    />
</ul>
