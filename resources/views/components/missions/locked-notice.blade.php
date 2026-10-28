@props(['gatingMission'])

{{-- Shown on the day overview of a mission the learner hasn't unlocked yet:
     they can look through every day and step, just not do any of it. Tapping
     it goes to the mission that has to be finished first. --}}
<x-preview-pill
    :label="$gatingMission ? 'Preview only · unlocks after '.$gatingMission->code : 'Preview only · unlocks after the missions before it'"
    :href="$gatingMission ? route('missions.show', [$gatingMission, 'overview']) : null"
/>
