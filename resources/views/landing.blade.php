@php
    /**
     * Copy lives here rather than in lang files because this page is the only consumer and the
     * two languages are easier to keep honest with each other side by side. Same reasoning as
     * legal/show.blade.php's $titles map.
     */
    $copy = [
        'en' => [
            'tagline' => 'Screenshots worth sharing',
            'meta' => 'Akukas notices the screenshot you just took and gives you one tap to share it — or to keep it somewhere private.',
            'hero_h1' => 'The screenshot you just took, already halfway shared.',
            'hero_p' => 'Take a screenshot the way you always do. Akukas notices, and a small card appears: post it, or tuck it away privately. No hunting through your gallery, no exporting, no third app.',
            'cta_play' => 'Get it on Google Play',
            'cta_soon' => 'Coming soon to Google Play',
            'cta_note' => 'Free. Android 8.0 and up.',
            'shot_share' => 'Share',
            'shot_save' => 'Save privately',
            'shot_caption' => 'Screenshot detected',
            'how_title' => 'Three steps, and you were going to do the first one anyway',
            'how' => [
                ['Take a screenshot', 'However you already do it — buttons, gesture, whatever your phone uses. Akukas does not change that.'],
                ['A card appears', 'Akukas notices the new screenshot and offers you two choices, right there, over whatever you were doing.'],
                ['Share it or keep it', 'Post it to your feed for people who follow you, or save it privately where nobody else can see it.'],
            ],
            'features_title' => 'And once it is in',
            'features' => [
                ['A feed worth reading', 'Two tabs: the people you follow, and a For You feed that learns what you actually stop on.'],
                ['Groups and messages', 'Share into a group, or send a screenshot straight to one person.'],
                ['Collections and folders', 'Organise what you post into collections, and what you keep into private folders only you can open.'],
                ['Find things again', 'Search posts, people and hashtags. Follow a hashtag and it shows up in your feed.'],
                ['Comment, reply, repost', 'Threaded replies, likes, and reposts — to your timeline or into a group.'],
                ['Your reach, your call', 'Hidden words, blocking, muting, private accounts, and a follower list you approve.'],
            ],
            'privacy_title' => 'A screenshot is a private thing until you decide otherwise',
            'privacy_p' => 'Screenshots stay on your device unless you post them. Anything you save privately is yours alone — it is never shown to anyone, never put in a feed, and never used to recommend anything. You can delete your account, and everything in it, from inside the app.',
            'privacy_link' => 'Read the privacy policy',
            'lang_switch' => 'العربية',
            'footer_privacy' => 'Privacy',
            'footer_terms' => 'Terms',
            'footer_csae' => 'Child safety',
            'footer_delete' => 'Delete your account',
            'footer_contact' => 'Contact',
        ],
        'ar' => [
            'tagline' => 'لقطات شاشة تستحق المشاركة',
            'meta' => 'يلاحظ أكوكاس لقطة الشاشة التي التقطتها للتو، ويمنحك نقرة واحدة لمشاركتها أو حفظها في مكان خاص.',
            'hero_h1' => 'لقطة الشاشة التي التقطتها للتو، في منتصف طريقها إلى المشاركة.',
            'hero_p' => 'التقط لقطة شاشة كما تفعل دائمًا. يلاحظها أكوكاس، فتظهر بطاقة صغيرة: انشرها، أو احفظها بشكل خاص. دون البحث في المعرض، ودون تصدير، ودون تطبيق ثالث.',
            'cta_play' => 'احصل عليه من Google Play',
            'cta_soon' => 'قريبًا على Google Play',
            'cta_note' => 'مجانًا. يتطلب أندرويد 8.0 فأحدث.',
            'shot_share' => 'مشاركة',
            'shot_save' => 'حفظ بشكل خاص',
            'shot_caption' => 'تم رصد لقطة شاشة',
            'how_title' => 'ثلاث خطوات، وكنت ستقوم بالأولى على أي حال',
            'how' => [
                ['التقط لقطة شاشة', 'بالطريقة التي تستخدمها أصلًا — الأزرار أو الإيماءة أو ما يعتمده هاتفك. لا يغيّر أكوكاس ذلك.'],
                ['تظهر بطاقة', 'يلاحظ أكوكاس اللقطة الجديدة ويعرض عليك خيارين، هناك مباشرة، فوق ما كنت تفعله.'],
                ['شاركها أو احتفظ بها', 'انشرها في موجزك لمن يتابعونك، أو احفظها بشكل خاص حيث لا يراها أحد سواك.'],
            ],
            'features_title' => 'وبعد أن تدخل',
            'features' => [
                ['موجز يستحق القراءة', 'علامتان: الأشخاص الذين تتابعهم، وموجز «مقترح لك» يتعلّم ما تتوقف عنده فعلًا.'],
                ['مجموعات ورسائل', 'شارك داخل مجموعة، أو أرسل لقطة شاشة مباشرة إلى شخص واحد.'],
                ['مجموعات ومجلدات', 'نظّم ما تنشره في مجموعات، وما تحتفظ به في مجلدات خاصة لا يفتحها سواك.'],
                ['اعثر على الأشياء مجددًا', 'ابحث في المنشورات والأشخاص والوسوم. تابِع وسمًا فيظهر في موجزك.'],
                ['علِّق وردّ وأعد النشر', 'ردود متسلسلة وإعجابات وإعادة نشر — إلى صفحتك أو داخل مجموعة.'],
                ['وصولك قرارك', 'كلمات مخفية، وحظر، وكتم، وحسابات خاصة، وقائمة متابعين توافق عليها بنفسك.'],
            ],
            'privacy_title' => 'لقطة الشاشة أمر خاص إلى أن تقرر غير ذلك',
            'privacy_p' => 'تبقى لقطات الشاشة على جهازك ما لم تنشرها. وكل ما تحفظه بشكل خاص يخصّك وحدك — لا يُعرض على أحد، ولا يدخل أي موجز، ولا يُستخدم في اقتراح أي شيء. ويمكنك حذف حسابك وكل ما فيه من داخل التطبيق.',
            'privacy_link' => 'اقرأ سياسة الخصوصية',
            'lang_switch' => 'English',
            'footer_privacy' => 'الخصوصية',
            'footer_terms' => 'الشروط',
            'footer_csae' => 'سلامة الأطفال',
            'footer_delete' => 'حذف الحساب',
            'footer_contact' => 'تواصل معنا',
        ],
    ];

    $t = $copy[$locale] ?? $copy['en'];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $brand }} — {{ $t['tagline'] }}</title>
    <meta name="description" content="{{ $t['meta'] }}">

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="canonical" href="{{ url('/') }}">
    <link rel="alternate" hreflang="en" href="{{ url('/?lang=en') }}">
    <link rel="alternate" hreflang="ar" href="{{ url('/?lang=ar') }}">

    {{-- Both values so the browser chrome matches whichever palette the visitor is in. --}}
    <meta name="theme-color" content="#FAF8F5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#141312" media="(prefers-color-scheme: dark)">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $brand }} — {{ $t['tagline'] }}">
    <meta property="og:description" content="{{ $t['meta'] }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="{{ $locale === 'ar' ? 'ar_LY' : 'en_US' }}">
    <meta name="twitter:card" content="summary">

    <style>
        /* Palette lifted from the Android app's own Material tokens (values/colors.xml and
           values-night/colors.xml) so the site and the app read as one product. */
        :root {
            color-scheme: light dark;
            --bg: #FAF8F5;
            --surface: #FFFFFF;
            --surface-2: #F3EEE7;
            --surface-3: #ECE4D9;
            --fg: #252321;
            --muted: #706B65;
            --primary: #A94F3D;
            --on-primary: #FFFFFF;
            --rule: #E2D8CC;
            --shadow: 0 1px 2px rgba(37, 35, 33, .05), 0 12px 32px -12px rgba(37, 35, 33, .18);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #141312;
                --surface: #1D1B1A;
                --surface-2: #252321;
                --surface-3: #2E2A28;
                --fg: #E6E1DF;
                --muted: #A9A29D;
                --primary: #FFB4A8;
                --on-primary: #3A1109;
                --rule: #332F2C;
                --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 12px 32px -12px rgba(0, 0, 0, .6);
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font: 17px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
                  "Noto Naskh Arabic", "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 68rem; margin: 0 auto; padding-inline: 1.5rem; }

        a { color: var(--primary); }

        /* ---- header ---------------------------------------------------------------- */
        .site-header { padding-block: 1.5rem; }
        .site-header .wrap { display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .brand { display: flex; align-items: center; gap: .6rem; font-weight: 650; font-size: 1.1rem; letter-spacing: -.01em; }
        .brand svg { width: 2.1rem; height: 2.1rem; color: var(--primary); display: block; }
        .lang {
            color: var(--muted); text-decoration: none; font-size: .9rem; font-weight: 550;
            border: 1px solid var(--rule); border-radius: 999px; padding: .35rem .85rem;
            transition: border-color .15s, color .15s;
        }
        .lang:hover { color: var(--fg); border-color: var(--muted); }

        /* ---- hero ------------------------------------------------------------------ */
        .hero { padding-block: 3rem 4.5rem; }
        .hero .wrap { display: grid; gap: 3.5rem; align-items: center; }
        @media (min-width: 60rem) {
            .hero { padding-block: 5rem 6.5rem; }
            .hero .wrap { grid-template-columns: 1.05fr .95fr; gap: 4.5rem; }
        }

        h1 {
            font-size: clamp(2.1rem, 5.2vw, 3.3rem);
            line-height: 1.12;
            letter-spacing: -.025em;
            font-weight: 700;
            margin: 0 0 1.1rem;
            text-wrap: balance;
        }
        .lede { font-size: 1.12rem; color: var(--muted); margin: 0 0 2rem; max-width: 34rem; }

        .cta { display: flex; flex-wrap: wrap; align-items: center; gap: .9rem 1.1rem; }
        .btn {
            display: inline-flex; align-items: center; gap: .6rem;
            background: var(--primary); color: var(--on-primary);
            text-decoration: none; font-weight: 600; font-size: 1rem;
            padding: .85rem 1.5rem; border-radius: 999px;
            transition: transform .12s ease, filter .15s ease;
        }
        .btn:hover { filter: brightness(1.06); transform: translateY(-1px); }
        .btn svg { width: 1.15rem; height: 1.15rem; }
        /* Not a link, because there is nowhere to go yet. Styled as a status, not a button. */
        .btn--soon {
            background: var(--surface-2); color: var(--fg);
            border: 1px dashed var(--rule); cursor: default;
        }
        .btn--soon:hover { filter: none; transform: none; }
        .cta-note { color: var(--muted); font-size: .9rem; }

        /* ---- the overlay moment ----------------------------------------------------- */
        .shot { display: flex; justify-content: center; }
        .phone {
            position: relative; width: min(19rem, 100%); aspect-ratio: 9 / 17.5;
            background: var(--surface-3); border: 1px solid var(--rule);
            border-radius: 2.2rem; box-shadow: var(--shadow); overflow: hidden;
        }
        /* Stand-in for a captured screen: shapes, not a fake screenshot of a real service. */
        .phone-canvas { position: absolute; inset: 0; padding: 1.5rem 1.15rem; display: flex; flex-direction: column; gap: .7rem; }
        .bar { background: var(--surface); border-radius: .5rem; opacity: .85; }
        .bar.h { height: .62rem; }
        .bar.tall { height: 5.5rem; }
        .bar.w70 { width: 70%; } .bar.w45 { width: 45%; } .bar.w85 { width: 85%; } .bar.w55 { width: 55%; }

        .overlay-card {
            position: absolute; inset-inline: .8rem; bottom: .9rem;
            background: color-mix(in srgb, var(--surface) 88%, transparent);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--rule); border-radius: 1.15rem;
            padding: .85rem; box-shadow: var(--shadow);
        }
        .overlay-label { font-size: .72rem; color: var(--muted); font-weight: 600; letter-spacing: .02em; margin: 0 0 .6rem; padding-inline-start: .25rem; }
        .overlay-actions { display: grid; gap: .45rem; }
        .overlay-btn {
            display: flex; align-items: center; gap: .6rem;
            font-size: .92rem; font-weight: 550; color: var(--fg);
            background: var(--surface-2); border-radius: .7rem; padding: .6rem .75rem;
        }
        .overlay-btn svg { width: 1.05rem; height: 1.05rem; color: var(--primary); flex: none; }

        /* ---- sections --------------------------------------------------------------- */
        section + section { padding-top: 1rem; }
        .band { background: var(--surface); border-block: 1px solid var(--rule); padding-block: 4rem; }
        h2 {
            font-size: clamp(1.5rem, 3.2vw, 2.1rem); line-height: 1.2; letter-spacing: -.02em;
            font-weight: 680; margin: 0 0 2.5rem; max-width: 32ch; text-wrap: balance;
        }

        .steps { display: grid; gap: 2rem; counter-reset: step; }
        @media (min-width: 48rem) { .steps { grid-template-columns: repeat(3, 1fr); gap: 2.5rem; } }
        .step h3 { font-size: 1.08rem; margin: 0 0 .45rem; font-weight: 620; }
        .step p { margin: 0; color: var(--muted); font-size: .97rem; }
        .step::before {
            counter-increment: step; content: counter(step);
            display: grid; place-items: center; width: 2rem; height: 2rem; margin-bottom: .9rem;
            border-radius: 999px; background: var(--primary); color: var(--on-primary);
            font-size: .9rem; font-weight: 700;
        }

        .features { display: grid; gap: 1rem; }
        @media (min-width: 42rem) { .features { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 64rem) { .features { grid-template-columns: repeat(3, 1fr); } }
        .feature { background: var(--surface-2); border: 1px solid var(--rule); border-radius: 1rem; padding: 1.35rem 1.4rem; }
        .feature h3 { font-size: 1.02rem; margin: 0 0 .4rem; font-weight: 620; }
        .feature p { margin: 0; color: var(--muted); font-size: .95rem; }

        .privacy { padding-block: 4.5rem; }
        .privacy-inner { max-width: 44rem; }
        .privacy p { color: var(--muted); font-size: 1.06rem; margin: 0 0 1.25rem; }
        .privacy a { font-weight: 550; }

        /* ---- footer ----------------------------------------------------------------- */
        footer { border-top: 1px solid var(--rule); padding-block: 2.5rem 3.5rem; }
        footer .wrap { display: flex; flex-wrap: wrap; gap: 1rem 1.75rem; align-items: center; justify-content: space-between; }
        .foot-links { display: flex; flex-wrap: wrap; gap: 1rem 1.5rem; }
        .foot-links a { color: var(--muted); text-decoration: none; font-size: .92rem; }
        .foot-links a:hover { color: var(--fg); text-decoration: underline; }
        .copyright { color: var(--muted); font-size: .88rem; }

        @media (prefers-reduced-motion: reduce) {
            .btn { transition: none; }
            .btn:hover { transform: none; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <div class="wrap">
        <div class="brand">
            @include('partials.akukas-mark')
            <span>{{ $brand }}</span>
        </div>
        <a class="lang" href="{{ url('/?lang='.$otherLocale) }}" hreflang="{{ $otherLocale }}">{{ $t['lang_switch'] }}</a>
    </div>
</header>

<main>
    <section class="hero">
        <div class="wrap">
            <div>
                <h1>{{ $t['hero_h1'] }}</h1>
                <p class="lede">{{ $t['hero_p'] }}</p>

                <div class="cta">
                    @if ($playUrl)
                        <a class="btn" href="{{ $playUrl }}" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3.6 1.8a1 1 0 0 0-.6.9v18.6a1 1 0 0 0 .6.9l10.1-10.2L3.6 1.8Zm11.5 8.6 2.9-2.9-11-6.3 8.1 9.2Zm0 3.2-8.1 9.2 11-6.3-2.9-2.9Zm1.4-1.4 3.4-1.9c.7-.4.7-1.4 0-1.8l-3.4-1.9-2.6 2.8 2.6 2.8Z"/></svg>
                            {{ $t['cta_play'] }}
                        </a>
                    @else
                        <span class="btn btn--soon">{{ $t['cta_soon'] }}</span>
                    @endif
                    <span class="cta-note">{{ $t['cta_note'] }}</span>
                </div>
            </div>

            <div class="shot">
                <div class="phone" role="img" aria-label="{{ $t['shot_caption'] }}: {{ $t['shot_share'] }} / {{ $t['shot_save'] }}">
                    {{-- Abstract shapes rather than a mock screenshot: nothing here should look
                         like a real person's content or another product's interface. --}}
                    <div class="phone-canvas" aria-hidden="true">
                        <div class="bar h w45"></div>
                        <div class="bar tall"></div>
                        <div class="bar h w85"></div>
                        <div class="bar h w70"></div>
                        <div class="bar tall"></div>
                        <div class="bar h w55"></div>
                        <div class="bar h w70"></div>
                    </div>

                    <div class="overlay-card" aria-hidden="true">
                        <p class="overlay-label">{{ $t['shot_caption'] }}</p>
                        <div class="overlay-actions">
                            <div class="overlay-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7"/><path d="M12 16V4"/><path d="m8 8 4-4 4 4"/></svg>
                                {{ $t['shot_share'] }}
                            </div>
                            <div class="overlay-btn">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                                {{ $t['shot_save'] }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="band">
        <div class="wrap">
            <h2>{{ $t['how_title'] }}</h2>
            <div class="steps">
                @foreach ($t['how'] as [$heading, $body])
                    <div class="step">
                        <h3>{{ $heading }}</h3>
                        <p>{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="privacy">
        <div class="wrap">
            <h2>{{ $t['features_title'] }}</h2>
            <div class="features">
                @foreach ($t['features'] as [$heading, $body])
                    <div class="feature">
                        <h3>{{ $heading }}</h3>
                        <p>{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="band">
        <div class="wrap privacy-inner">
            <h2>{{ $t['privacy_title'] }}</h2>
            <p>{{ $t['privacy_p'] }}</p>
            <a href="{{ route('legal.show', ['document' => 'privacy', 'locale' => $locale]) }}">{{ $t['privacy_link'] }} &rarr;</a>
        </div>
    </section>
</main>

<footer>
    <div class="wrap">
        <nav class="foot-links">
            <a href="{{ route('legal.show', ['document' => 'privacy', 'locale' => $locale]) }}">{{ $t['footer_privacy'] }}</a>
            <a href="{{ route('legal.show', ['document' => 'terms', 'locale' => $locale]) }}">{{ $t['footer_terms'] }}</a>
            <a href="{{ route('legal.show', ['document' => 'csae', 'locale' => $locale]) }}">{{ $t['footer_csae'] }}</a>
            <a href="{{ route('legal.show', ['document' => 'account-deletion', 'locale' => $locale]) }}">{{ $t['footer_delete'] }}</a>
            <a href="mailto:akukasapp@gmail.com">{{ $t['footer_contact'] }}</a>
        </nav>
        <p class="copyright">&copy; {{ date('Y') }} {{ $brand }}</p>
    </div>
</footer>

</body>
</html>
