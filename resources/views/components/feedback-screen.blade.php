{{--
    The feedback screen every speaking and writing step ends on, one
    lesson screen of its own: a "Feedback" chip, the feedback card
    (<x-learner-feedback>: summary, then one card per answer or per
    sentence that needs work, then the better version), with "Next time"
    and what the learner said folded under "More", and the step's
    Continue at the bottom. While the feedback is still being written it
    shows the thinking line instead.

    @param string $when Raw Alpine expression for when this screen shows.
    @param array|null $feedback The saved feedback, null while waiting or
        when the AI was down.
    @param list<array{question: ?string, text: string}> $answers See <x-learner-feedback>.
    @param bool $waiting True while the feedback is still being written.
    @slot said What the learner said or wrote (recordings, the full text), folded.
    @slot actions The step's Continue.
--}}
@props(['when', 'feedback' => null, 'answers' => [], 'waiting' => false])

<x-lesson-screen :when="$when" {{ $attributes }}>
    <x-step-intro kind="Feedback" />

    @if ($waiting && $feedback === null)
        <x-ai-thinking label="Checking your English…" />
    @else
        <x-learner-feedback :feedback="$feedback" :answers="$answers" fold-techniques>
            @isset($said)
                <x-slot:more>{{ $said }}</x-slot:more>
            @endisset
        </x-learner-feedback>
    @endif

    @isset($actions)
        <x-slot:actions>{{ $actions }}</x-slot:actions>
    @endisset
</x-lesson-screen>
