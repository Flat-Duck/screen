{{--
    The four pillar glyphs, drawn on one 24px grid with one stroke weight so the row reads as a
    set. Requires: $icon — capture | share | connect | control.
--}}
@switch($icon)
    @case('share')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 16.5A3.5 3.5 0 0 1 5 9.7a5 5 0 0 1 9.6-1.6A4 4 0 0 1 19.5 16"/>
            <path d="M12 21v-8"/>
            <path d="m9 15.5 3-3 3 3"/>
        </svg>
        @break

    @case('connect')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="9" cy="8" r="3.2"/>
            <path d="M3 19a6 6 0 0 1 12 0"/>
            <path d="M16.5 5.6a3.2 3.2 0 0 1 0 6"/>
            <path d="M18 13.4A6 6 0 0 1 21.5 19"/>
        </svg>
        @break

    @case('control')
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2.8 20 6v6.1c0 4.4-3.2 7.9-8 9.1-4.8-1.2-8-4.7-8-9.1V6l8-3.2Z"/>
            <rect x="9.2" y="10.6" width="5.6" height="4.6" rx="1.1"/>
            <path d="M10.6 10.6V9.3a1.4 1.4 0 0 1 2.8 0v1.3"/>
        </svg>
        @break

    @default
        {{-- capture --}}
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 8.5A1.5 1.5 0 0 1 4.5 7h2.2l1.2-2h8.2l1.2 2h2.2A1.5 1.5 0 0 1 21 8.5v9A1.5 1.5 0 0 1 19.5 19h-15A1.5 1.5 0 0 1 3 17.5v-9Z"/>
            <circle cx="12" cy="13" r="3.4"/>
        </svg>
@endswitch
