{{--
    The Saharan escarpment behind the closing call to action, as layered SVG rather than a
    photograph. A drawing carries no licence, no attribution and no 400KB hero image, and it
    recolours itself for dark mode — which a photo of a sunlit desert cannot do.

    Swap it for real photography by giving `.cta` a background-image; the markup already sits
    behind the content and needs no other change.
--}}
<svg class="ridges" viewBox="0 0 1440 420" preserveAspectRatio="xMidYMax slice"
     xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="var(--ridge-sky-top)"/>
            <stop offset="100%" stop-color="var(--ridge-sky-bottom)"/>
        </linearGradient>
    </defs>

    <rect width="1440" height="420" fill="url(#sky)"/>
    <circle cx="1050" cy="196" r="58" fill="var(--ridge-sun)"/>

    {{-- Far range, then progressively nearer and darker — the only depth cue a flat drawing has. --}}
    <path fill="var(--ridge-far)"
          d="M0 300 L84 258 L150 282 L232 226 L318 274 L402 232 L470 268 L556 214 L640 262
             L724 236 L806 278 L900 240 L982 276 L1074 244 L1160 284 L1250 250 L1338 288
             L1440 254 L1440 420 L0 420 Z"/>
    <path fill="var(--ridge-mid)"
          d="M0 342 L96 306 L168 330 L262 288 L344 326 L436 296 L520 334 L618 300 L706 338
             L800 306 L890 340 L988 302 L1080 336 L1178 306 L1272 342 L1366 314 L1440 336
             L1440 420 L0 420 Z"/>
    {{-- Near mesa: flat-topped, the shape the Acacus is actually known for. --}}
    <path fill="var(--ridge-near)"
          d="M0 386 L110 366 L150 344 L268 344 L306 368 L420 356 L470 330 L612 330 L654 358
             L760 372 L820 350 L946 350 L986 374 L1104 364 L1150 342 L1286 342 L1326 368
             L1440 380 L1440 420 L0 420 Z"/>
</svg>
