{{--
    The app's brand mark — a rising sun over a horizon, echoing the "A Day,
    Well Spent" design direction (EOS-009 §8) and the --color-dawn/
    --color-accent tokens already used for the mission hero's glow. Colors
    are deliberately fixed (not theme- or mood-driven), same as
    --color-hero/-hero-2 — a stable brand identity, not something that
    shifts per mission or between light/dark.

    @param string $iconClass Tailwind sizing classes for the mark, e.g. "h-8 w-8".
    @param bool $withText Whether to show the "English OS" wordmark next to the mark.
    @param string $textClass Tailwind classes for the wordmark text.
--}}
@props(['iconClass' => 'h-8 w-8', 'withText' => true, 'textClass' => 'text-lg'])

<span class="inline-flex items-center gap-2">
    <svg viewBox="0 0 64 64" class="{{ $iconClass }} shrink-0" aria-hidden="true">
        <defs>
            <linearGradient id="eos-logo-bg" x1="0" y1="0" x2="64" y2="64" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#322d5c" />
                <stop offset="1" stop-color="#211d3f" />
            </linearGradient>
            <linearGradient id="eos-logo-sun" x1="32" y1="23" x2="32" y2="40" gradientUnits="userSpaceOnUse">
                <stop offset="0" stop-color="#ffb17a" />
                <stop offset="1" stop-color="#ff6b4a" />
            </linearGradient>
            <clipPath id="eos-logo-sun-clip">
                <path d="M16 40 A16 16 0 0 1 48 40 Z" />
            </clipPath>
        </defs>

        <rect width="64" height="64" rx="16" fill="url(#eos-logo-bg)" />

        <g clip-path="url(#eos-logo-sun-clip)">
            <rect x="12" y="23" width="40" height="17" fill="url(#eos-logo-sun)" />
            <rect x="12" y="31" width="40" height="2" fill="#322d5c" opacity="0.5" />
            <rect x="12" y="35.5" width="40" height="2" fill="#322d5c" opacity="0.5" />
        </g>
    </svg>

    @if ($withText)
        <span class="font-display font-bold text-ink dark:text-ink-dark {{ $textClass }}">English <span class="text-accent-ink dark:text-accent-ink-dark">OS</span></span>
    @endif
</span>
