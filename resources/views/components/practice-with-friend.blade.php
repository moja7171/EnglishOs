{{--
    A lightweight bridge from a mission's content to the Friends messaging
    system — deliberately NOT part of Evidence Before Progress (Article 3):
    it never records anything for the mission run, just opens the friend's
    conversation page with a message pre-filled in the composer, ready to
    edit or send. The learner still has to do the step themselves for it
    to advance; this is purely an optional way to also talk about the same
    thing with a real person.

    @param string $text The question/topic/prompt to share.
    @param string $intro Leading phrase before the quoted text, e.g.
        "Hey — want to help me practice this:" (default) or "Hey — want to
        talk about this with me:" for a topic rather than a drill question.
    @param string $label Button text.
--}}
@props([
    'text',
    'intro' => 'Hey — want to help me practice this:',
    'label' => 'Practice this with a friend',
])

@php
    // Built as a plain variable (not inlined into the tag below) since the
    // quoted text embeds literal double quotes, which Blade's component
    // tag attribute parser can't reliably scan past when written directly
    // inside a "..." attribute.
    $hrefFor = fn ($friend) => route('friends.conversation', [
        'user' => $friend,
        'prefill' => "{$intro} \"{$text}\"",
    ]);
@endphp

<x-friend-picker :label="$label" :href-for="$hrefFor" />
