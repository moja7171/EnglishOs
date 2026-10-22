@props(['practice'])

{{-- The daily voice practice with Pi, shown inside the Today box as one
     quiet row that opens /pi. Same family as <x-listening-today>, but it sits
     BELOW the day's steps: it is meant as the last 10 minutes of the day.
     Like the listening row it is NOT one of the steps (no check circles, no
     numbers, never gates Continue, always open). The prompt, the links and
     the "I practiced" tick all live on that page — the box only points there
     and reflects whether today's tick has been made. --}}
<x-today-row
    :href="route('pi.practice')"
    :title="'Voice practice · '.$practice['title']"
    label="Daily voice practice"
    :done="$practice['practicedToday']"
    :hint="$practice['practicedToday'] ? 'Practiced today' : 'Last 10 minutes of your day'"
    tick="markPracticed"
    tick-label="I practiced"
/>
