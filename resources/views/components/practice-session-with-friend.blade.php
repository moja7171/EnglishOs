{{--
    Opens a shared, structured Partner Session (see PartnerSession) for
    this mission+step — every question in the step, both people's answers
    side by side. Distinct from <x-practice-with-friend>, which just
    shares ONE question via a pre-filled DM: this is for going through
    the WHOLE set together.

    @param \App\Models\Mission $mission
    @param string $stepKey
--}}
@props(['mission', 'stepKey'])

@php
    $hrefFor = fn ($friend) => route('missions.practice-with-friend', [
        'mission' => $mission,
        'step' => $stepKey,
        'friend' => $friend,
    ]);
@endphp

<x-friend-picker label="Do this with a partner" :href-for="$hrefFor" />
