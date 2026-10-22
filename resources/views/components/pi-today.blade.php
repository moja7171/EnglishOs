@props(['practice', 'current' => false])

{{-- The daily voice practice with Pi as the last line of the Today box's
     checklist (<x-today-row>), opening /pi: the last 10 minutes of the day.
     The prompt and links live on that page; the circle here is the same
     "I practiced" tick, so it can be made without leaving home. --}}
<x-today-row
    :href="route('pi.practice')"
    :title="'Voice practice · '.$practice['title']"
    label="Daily voice practice"
    :minutes="\App\Services\PiPractice::MINUTES"
    :done="$practice['practicedToday']"
    :current="$current"
    tick="markPracticed"
    tick-label="I practiced"
/>
