{{--
    Why something could not be done (the AI did not answer, a recording did
    not upload): a clear red card where the learner is looking, not a small
    line under the box. Pass the message as the slot.
--}}
<div role="alert" {{ $attributes->class('rounded-2xl bg-danger-soft px-4 py-3 text-sm font-semibold text-danger-ink') }}>{{ $slot }}</div>
