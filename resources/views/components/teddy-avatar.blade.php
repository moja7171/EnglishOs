{{--
    Teddy, the AI classmate: a cartoon face drawn here, the same for
    everyone, so he reads as one of the friends. Plain shapes only; the
    round clip keeps it a circle at any size.
--}}
<svg {{ $attributes->class(['inline-block shrink-0 overflow-hidden rounded-full']) }} viewBox="0 0 100 100" role="img" aria-label="Teddy" xmlns="http://www.w3.org/2000/svg">
    <rect width="100" height="100" fill="#fcd34d" />
    {{-- shoulders and shirt --}}
    <path d="M10 100c0-18 14-26 40-26s40 8 40 26z" fill="#f97316" />
    <path d="M40 76l10 10 10-10z" fill="#fff7ed" />
    {{-- neck, ears, face --}}
    <rect x="42" y="64" width="16" height="14" rx="5" fill="#d99a6c" />
    <circle cx="23" cy="50" r="6.5" fill="#e9a977" />
    <circle cx="77" cy="50" r="6.5" fill="#e9a977" />
    <ellipse cx="50" cy="48" rx="27" ry="29" fill="#f2b98a" />
    {{-- messy hair --}}
    <path d="M22 44c-4-22 10-33 28-33s34 11 28 33c-3-9-8-14-14-15-8 5-24 5-32 0-6 1-8 6-10 15z" fill="#5b3a29" />
    <path d="M44 14c2-7 10-9 13-3-4-1-8 0-13 3z" fill="#5b3a29" />
    <path d="M56 14c5-6 13-5 14 2-4-3-9-3-14-2z" fill="#5b3a29" />
    {{-- eyebrows: one raised --}}
    <path d="M30 36c4-4 11-4 14-1" fill="none" stroke="#5b3a29" stroke-width="3" stroke-linecap="round" />
    <path d="M57 33c4-4 11-4 14 0" fill="none" stroke="#5b3a29" stroke-width="3" stroke-linecap="round" />
    {{-- eyes --}}
    <circle cx="37" cy="44" r="6" fill="#fff" />
    <circle cx="63" cy="44" r="6" fill="#fff" />
    <circle cx="38.5" cy="45" r="3.2" fill="#2b1b12" />
    <circle cx="64.5" cy="45" r="3.2" fill="#2b1b12" />
    <circle cx="39.7" cy="43.6" r="1.1" fill="#fff" />
    <circle cx="65.7" cy="43.6" r="1.1" fill="#fff" />
    {{-- round glasses --}}
    <circle cx="37" cy="44" r="9" fill="none" stroke="#1f2937" stroke-width="2.4" />
    <circle cx="63" cy="44" r="9" fill="none" stroke="#1f2937" stroke-width="2.4" />
    <path d="M46 43c2.5-2 5.5-2 8 0" fill="none" stroke="#1f2937" stroke-width="2.4" />
    {{-- nose, cheeks, big grin with teeth --}}
    <path d="M50 49c-2 5-3 7 0 8" fill="none" stroke="#c9825a" stroke-width="2.2" stroke-linecap="round" />
    <circle cx="28" cy="58" r="4.5" fill="#f87171" opacity=".45" />
    <circle cx="72" cy="58" r="4.5" fill="#f87171" opacity=".45" />
    <path d="M34 62c4 14 28 14 32 0z" fill="#7f1d1d" />
    <path d="M36 62h28c-1 4-4 6-6 6H42c-2 0-5-2-6-6z" fill="#fff" />
    <path d="M42 71c4 3 12 3 16 0-3-2-13-2-16 0z" fill="#f87171" />
</svg>
