{{--
    Tadrart Acacus rock-art motifs, drawn rather than photographed.

    The brand name comes from Jebel Akakus, so the dancing-figure and antelope engravings of the
    Libyan Sahara are the product's own reference rather than borrowed decoration. Drawn as SVG
    (not traced from a specific engraving) so nothing here is a photograph of a protected site,
    and so it stays crisp at any size and takes `currentColor` in either theme.

    Usage: @include('partials.rock-art', ['figure' => 'dancers'])  — dancers | antelope | runner
--}}
@php $figure = $figure ?? 'dancers'; @endphp

@if ($figure === 'runner')
    {{-- Single striding figure. Used at logo scale, so the strokes are heavier. --}}
    <svg viewBox="0 0 32 40" fill="none" stroke="currentColor" stroke-width="3.4"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <circle cx="17" cy="5.5" r="4" fill="currentColor" stroke="none"/>
        <path d="M17 10.5 L15 22"/>
        <path d="M16 13.5 L25 10"/>
        <path d="M16 14.5 L8 12.5 L6 6"/>
        <path d="M15 22 L22 31 L21 38"/>
        <path d="M15 22 L8 30 L4 35"/>
    </svg>
@elseif ($figure === 'antelope')
    <svg viewBox="0 0 64 48" fill="none" stroke="currentColor" stroke-width="2.6"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M14 22 C20 17, 40 17, 48 21"/>
        <path d="M48 21 C52 21, 54 18, 55 15"/>
        <path d="M55 15 C57 10, 58 6, 57 3"/>
        <path d="M55 15 C56 10, 54 6, 51 4"/>
        <path d="M16 22 L13 38"/>
        <path d="M22 23 L20 39"/>
        <path d="M42 22 L44 38"/>
        <path d="M47 22 L49 39"/>
        <path d="M14 22 C10 23, 8 26, 7 30"/>
    </svg>
@else
    {{-- The four-dancer frieze. Deliberately uneven — engraved figures never match. --}}
    <svg viewBox="0 0 200 72" fill="none" stroke="currentColor" stroke-width="3"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <g>
            <circle cx="24" cy="12" r="5" fill="currentColor" stroke="none"/>
            <path d="M24 18 L24 40"/>
            <path d="M24 23 L10 12"/>
            <path d="M24 23 L38 13"/>
            <path d="M24 40 L13 62"/>
            <path d="M24 40 L35 62"/>
        </g>
        <g>
            <circle cx="76" cy="20" r="4.4" fill="currentColor" stroke="none"/>
            <path d="M76 25 L76 44"/>
            <path d="M76 30 L64 22"/>
            <path d="M76 30 L88 21"/>
            <path d="M76 44 L67 64"/>
            <path d="M76 44 L86 64"/>
        </g>
        <g>
            <circle cx="126" cy="10" r="5.2" fill="currentColor" stroke="none"/>
            <path d="M126 16 L126 39"/>
            <path d="M126 21 L112 14"/>
            <path d="M126 21 L141 10"/>
            <path d="M126 39 L115 63"/>
            <path d="M126 39 L138 62"/>
        </g>
        <g>
            <circle cx="174" cy="24" r="4" fill="currentColor" stroke="none"/>
            <path d="M174 28.5 L174 46"/>
            <path d="M174 33 L163 27"/>
            <path d="M174 33 L185 26"/>
            <path d="M174 46 L166 64"/>
            <path d="M174 46 L183 64"/>
        </g>
    </svg>
@endif
