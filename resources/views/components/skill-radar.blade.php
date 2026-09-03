{{--
    A hand-rolled SVG radar/spider chart — no charting library, same
    "small pure-SVG/Canvas component" pattern as <x-progress-ring> and the
    confetti burst. Plots average "after" self-assessment scores (0-5)
    per skill across every completed mission (see User::skillAverages()),
    a strengths/weaknesses snapshot the app never surfaced visually
    before now (the numbers existed per-mission but were never aggregated).

    @param array<string, float> $skills e.g. ['Listening' => 4.2, 'Vocabulary' => 3.8, ...]
    @param float $max The scale ceiling (self-assessment is 1-5).
--}}
@props(['skills' => [], 'max' => 5])

@php
    $labels = array_keys($skills);
    $values = array_values($skills);
    $count = count($labels);
    $size = 220;
    $center = $size / 2;
    $radius = $size / 2 - 34;
    $rings = [0.2, 0.4, 0.6, 0.8, 1.0];

    $angleFor = fn (int $i) => (2 * M_PI * $i / max($count, 1)) - M_PI / 2;

    $points = [];
    $labelPoints = [];

    foreach ($values as $i => $value) {
        $angle = $angleFor($i);
        $r = $radius * (min($value, $max) / $max);
        $points[] = round($center + $r * cos($angle), 1).','.round($center + $r * sin($angle), 1);

        $labelRadius = $radius + 18;
        $labelPoints[] = [
            'x' => round($center + $labelRadius * cos($angle), 1),
            'y' => round($center + $labelRadius * sin($angle), 1),
            'label' => $labels[$i],
        ];
    }
@endphp

@if ($count >= 3)
    <div {{ $attributes }}>
        <svg viewBox="0 0 {{ $size }} {{ $size }}" class="mx-auto w-full max-w-[240px]">
            @foreach ($rings as $ringScale)
                @php
                    $ringPoints = collect(range(0, $count - 1))
                        ->map(function (int $i) use ($angleFor, $center, $radius, $ringScale) {
                            $angle = $angleFor($i);
                            $r = $radius * $ringScale;

                            return round($center + $r * cos($angle), 1).','.round($center + $r * sin($angle), 1);
                        })
                        ->implode(' ');
                @endphp
                <polygon points="{{ $ringPoints }}" fill="none" class="stroke-line dark:stroke-line-dark" stroke-width="1"></polygon>
            @endforeach

            @for ($i = 0; $i < $count; $i++)
                @php $angle = $angleFor($i); @endphp
                <line
                    x1="{{ $center }}" y1="{{ $center }}"
                    x2="{{ round($center + $radius * cos($angle), 1) }}" y2="{{ round($center + $radius * sin($angle), 1) }}"
                    class="stroke-line dark:stroke-line-dark" stroke-width="1"
                ></line>
            @endfor

            <polygon points="{{ implode(' ', $points) }}" class="fill-accent-soft stroke-accent dark:fill-accent-soft-dark dark:stroke-accent-dark" stroke-width="2"></polygon>

            @foreach ($labelPoints as $i => $labelPoint)
                <text
                    x="{{ $labelPoint['x'] }}" y="{{ $labelPoint['y'] }}"
                    text-anchor="middle" dominant-baseline="middle"
                    class="fill-ink-faint text-[9px] font-semibold dark:fill-ink-faint-dark"
                >{{ $labelPoint['label'] }}</text>
                <text
                    x="{{ $labelPoint['x'] }}" y="{{ $labelPoint['y'] + 10 }}"
                    text-anchor="middle" dominant-baseline="middle"
                    class="fill-accent-ink text-[9px] font-bold dark:fill-accent-ink-dark"
                >{{ $values[$i] }}</text>
            @endforeach
        </svg>
    </div>
@endif
