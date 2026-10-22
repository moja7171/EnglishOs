@props(['listening'])

{{-- The daily listening habit, shown at the bottom of the Today box as one
     quiet row that opens /listening (see <x-today-row>: the circle is the
     tick, so it can be made without leaving home). The three picks, their levels, the
     transcript links and the "I listened" tick all live on that page — the
     box only points there and reflects whether today's tick has been made.
     Deliberately NOT styled like the mission steps (no check circles, no
     numbers, no current state) and kept out of the day's order: one pick,
     any time of day, never gates Continue. Named apart from the steps'
     own "Listen Again" so the two are never taken for each other. --}}
<x-today-row
    :href="route('listening.show')"
    title="Extra listening"
    label="Extra listening"
    :done="$listening['listenedToday']"
    :hint="$listening['listenedToday'] ? 'Listened today'.($listening['listensToday'] > 0 ? ' · '.$listening['listensToday'].'×' : '') : 'Anytime · one is enough'"
    tick="markListened"
    tick-label="I listened"
/>
