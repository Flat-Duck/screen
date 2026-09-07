@php
    /**
     * Copy lives here rather than in lang files because this page is the only consumer and the
     * two languages are easier to keep honest with each other side by side. Same reasoning as
     * legal/show.blade.php's $titles map.
     */
    $copy = [
        'en' => [
            'tagline' => 'Moments that matter',
            'meta' => 'Akukas notices the screenshot you just took and gives you one tap to share it with your world — or to keep it somewhere private.',

            'nav_home' => 'Home',
            'nav_features' => 'Features',
            'nav_about' => 'About',
            'nav_download' => 'Download',

            'hero_eyebrow' => ['Capture', 'Share', 'Discover'],
            'hero_h1_a' => 'Real Moments,',
            'hero_h1_b' => 'Not Just Screenshots',
            'hero_p' => 'Akukas is the simplest way to keep what you see, share it with your world, and be part of a community that values real, unfiltered moments.',
            'hero_note' => 'Inspired by ancient stories, built for today.',
            'phone_tagline' => 'Moments that matter',

            'cta_play' => 'Get it on Google Play',
            'cta_get_it_on' => 'Get it on',
            'cta_soon' => 'Coming soon to Google Play',
            'cta_note' => 'Download for free',

            'pillars' => [
                ['capture', 'Capture', 'Screenshot however you already do. Akukas notices the moment it lands.'],
                ['share', 'Share', 'Post it, and let the people who follow you see what you saw.'],
                ['connect', 'Connect', 'Be part of a community that shares your interests.'],
                ['control', 'Stay in Control', 'Your privacy matters. What you keep private stays private.'],
            ],

            'diary_eyebrow' => 'More than just a screenshot',
            'diary_h2_a' => 'A Visual Diary',
            'diary_h2_b' => 'of the World Around You',
            'diary_p' => 'From a view worth keeping to the small things you would otherwise scroll past, Akukas helps you save and share what caught your eye — and discover what caught someone else\'s.',
            'diary_list' => [
                'A feed for the people you follow, and one that learns',
                'Groups, direct messages and threaded replies',
                'Collections to post, private folders to keep',
                'Hashtags, search and trending moments',
            ],

            'about_eyebrow' => 'Where the name comes from',
            'about_h2' => 'A screenshot is a private thing until you decide otherwise',
            'about_p' => 'Akukas takes its name from Jebel Akakus, where people have been leaving pictures on rock for twelve thousand years. The instinct is the same one you have when something is worth keeping. Screenshots stay on your device unless you post them. Anything you save privately is yours alone — never shown to anyone, never put in a feed, never used to recommend anything.',
            'about_link' => 'Read the privacy policy',

            'closing_eyebrow' => 'Ready to see what others see?',
            'closing_h2' => 'Join Akukas Today',
            'closing_p' => 'Download the app and start keeping your world.',

            'lang_switch' => 'العربية',
            'foot_tagline' => ['Real Moments', 'A Bigger Community'],
            'footer_privacy' => 'Privacy',
            'footer_terms' => 'Terms',
            'footer_csae' => 'Child safety',
            'footer_delete' => 'Delete account',
            'footer_contact' => 'Contact',
            'photo_by' => 'Landscape photograph by',
            'photo_on' => 'on',
        ],
        'ar' => [
            'tagline' => 'لحظات لها معنى',
            'meta' => 'يلاحظ أكوكاس لقطة الشاشة التي التقطتها للتو، ويمنحك نقرة واحدة لمشاركتها مع عالمك، أو لحفظها في مكان خاص.',

            'nav_home' => 'الرئيسية',
            'nav_features' => 'المزايا',
            'nav_about' => 'عن التطبيق',
            'nav_download' => 'تحميل',

            'hero_eyebrow' => ['التقاط', 'مشاركة', 'اكتشاف'],
            'hero_h1_a' => 'لحظات حقيقية،',
            'hero_h1_b' => 'لا مجرد لقطات شاشة',
            'hero_p' => 'أكوكاس أبسط طريقة للاحتفاظ بما تراه، ومشاركته مع عالمك، والانضمام إلى مجتمع يقدّر اللحظات الحقيقية بلا تصنّع.',
            'hero_note' => 'مستوحى من حكايات قديمة، ومصنوع لليوم.',
            'phone_tagline' => 'لحظات لها معنى',

            'cta_play' => 'احصل عليه من Google Play',
            'cta_get_it_on' => 'احصل عليه على',
            'cta_soon' => 'قريبًا على Google Play',
            'cta_note' => 'حمله الأن',

            'pillars' => [
                ['capture', 'التقاط', 'التقط لقطة الشاشة كما تفعل دائمًا، ويلاحظها أكوكاس فور وصولها.'],
                ['share', 'مشاركة', 'انشرها، ودع من يتابعونك يرون ما رأيته.'],
                ['connect', 'تواصل', 'كن جزءًا من مجتمع يشاركك اهتماماتك.'],
                ['control', 'أنت المتحكم', 'خصوصيتك تهمّنا. وما تحفظه بشكل خاص يبقى خاصًا.'],
            ],

            'diary_eyebrow' => 'أكثر من مجرد لقطة شاشة',
            'diary_h2_a' => 'مفكرة مصوّرة',
            'diary_h2_b' => 'للعالم من حولك',
            'diary_p' => 'من منظر يستحق الاحتفاظ به إلى التفاصيل الصغيرة التي كنت ستتجاوزها، يساعدك أكوكاس على حفظ ما لفت نظرك ومشاركته — واكتشاف ما لفت نظر غيرك.',
            'diary_list' => [
                'موجز لمن تتابعهم، وآخر يتعلّم منك',
                'مجموعات ورسائل مباشرة وردود متسلسلة',
                'مجموعات للنشر، ومجلدات خاصة للحفظ',
                'وسوم وبحث ولحظات رائجة',
            ],

            'about_eyebrow' => 'من أين جاء الاسم',
            'about_h2' => 'لقطة الشاشة أمر خاص إلى أن تقرر غير ذلك',
            'about_p' => 'يأخذ أكوكاس اسمه من جبال أكاكوس، حيث ظل الناس يتركون صورهم على الصخر منذ اثني عشر ألف عام. وهي الرغبة نفسها التي تراودك حين ترى ما يستحق الاحتفاظ به. تبقى لقطات الشاشة على جهازك ما لم تنشرها، وكل ما تحفظه بشكل خاص يخصّك وحدك — لا يُعرض على أحد، ولا يدخل أي موجز، ولا يُستخدم في اقتراح أي شيء.',
            'about_link' => 'اقرأ سياسة الخصوصية',

            'closing_eyebrow' => 'مستعد لترى ما يراه الآخرون؟',
            'closing_h2' => 'انضم إلى أكوكاس اليوم',
            'closing_p' => 'حمّل التطبيق وابدأ بحفظ عالمك.',

            'lang_switch' => 'English',
            'foot_tagline' => ['لحظات حقيقية', 'مجتمع أوسع'],
            'footer_privacy' => 'الخصوصية',
            'footer_terms' => 'الشروط',
            'footer_csae' => 'سلامة الأطفال',
            'footer_delete' => 'حذف الحساب',
            'footer_contact' => 'تواصل معنا',
            'photo_by' => 'صورة المنظر الطبيعي بعدسة',
            'photo_on' => 'عبر',
        ],
    ];

    $t = $copy[$locale] ?? $copy['en'];
    $isArabic = $locale === 'ar';
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

    <meta name="theme-color" content="#FDF9F5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#141312" media="(prefers-color-scheme: dark)">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $brand }} — {{ $t['tagline'] }}">
    <meta property="og:description" content="{{ $t['meta'] }}">
    <meta property="og:url" content="{{ url('/') }}">
    <meta property="og:locale" content="{{ $isArabic ? 'ar_LY' : 'en_US' }}">
    <meta name="twitter:card" content="summary">

    {{-- Only the faces this locale actually renders. Amiri is the Arabic display face because a
    Didone-ish Latin serif has no Arabic, and a sans headline beside a serif one would read
    as two different sites. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @if ($isArabic)
        <link
            href="https://fonts.googleapis.com/css2?family=Amiri:wght@400;700&family=Noto+Sans+Arabic:wght@400;500;600&display=swap"
            rel="stylesheet">
    @else
        <link
            href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@500;600&family=Inter:wght@400;500;600&display=swap"
            rel="stylesheet">
    @endif

    <style>
        /* Palette from designs/warm_minimal.md, which is the same token set the Android app's
           values/colors.xml is generated from — so the site and the app are one product. */
        :root {
            color-scheme: light dark;
            --bg: #FDF9F5;
            --surface: #FFFFFF;
            --surface-2: #F7F3F0;
            --surface-3: #F1EDEA;
            --fg: #1C1B1A;
            --muted: #57423F;
            --soft: #8A716E;
            --primary: #9F3B2E;
            --on-primary: #FFFFFF;
            --ink: #2A2320;
            --rule: #E4DAD3;
            --shadow: 0 1px 2px rgba(28, 27, 26, .04), 0 24px 48px -24px rgba(88, 52, 40, .28);

            --ridge-sky-top: #F6E3D4;
            --ridge-sky-bottom: #EFD3C2;
            --ridge-sun: #FBEFE4;
            --ridge-far: #DCBBA8;
            --ridge-mid: #C79E8A;
            --ridge-near: #A87A66;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #141312;
                --surface: #1D1B1A;
                --surface-2: #211E1D;
                --surface-3: #2A2624;
                --fg: #E6E1DF;
                --muted: #BDB2AE;
                --soft: #9A8C88;
                --primary: #FFB4A8;
                --on-primary: #410000;
                --ink: #E6E1DF;
                --rule: #332F2C;
                --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 24px 48px -24px rgba(0, 0, 0, .75);

                --ridge-sky-top: #2A211D;
                --ridge-sky-bottom: #221A17;
                --ridge-sun: #3E2C24;
                --ridge-far: #3A2B25;
                --ridge-mid: #2F2320;
                --ridge-near: #241B18;
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--fg);
            font-family:
                {!! $isArabic ? '"Noto Sans Arabic"' : 'Inter' !!}
                , -apple-system, BlinkMacSystemFont,
                "Segoe UI", Roboto, "Noto Naskh Arabic", Arial, sans-serif;
            font-size: 16.5px;
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        .wrap {
            max-width: 74rem;
            margin: 0 auto;
            padding-inline: 1.75rem;
        }

        a {
            color: inherit;
        }

        .display {
            font-family:
                {!! $isArabic ? 'Amiri' : '"Playfair Display"' !!}
                , Georgia, "Times New Roman", serif;
            font-weight:
                {{ $isArabic ? '700' : '600' }}
            ;
            letter-spacing:
                {{ $isArabic ? '0' : '-.015em' }}
            ;
            line-height: 1.15;
        }

        .eyebrow {
            font-size: .74rem;
            font-weight: 600;
            color: var(--primary);
            text-transform:
                {{ $isArabic ? 'none' : 'uppercase' }}
            ;
            letter-spacing:
                {{ $isArabic ? '.02em' : '.18em' }}
            ;
            margin: 0 0 1.1rem;
        }

        .eyebrow .dot {
            color: var(--soft);
            margin-inline: .5rem;
        }

        /* ---- header ------------------------------------------------------------------ */
        .site-header {
            position: sticky;
            top: 0;
            z-index: 20;
            background: color-mix(in srgb, var(--bg) 86%, transparent);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .site-header .wrap {
            display: flex;
            align-items: center;
            gap: 1.25rem;
            padding-block: 1.15rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: .7rem;
            text-decoration: none;
        }

        .brand .mark {
            width: 1.95rem;
            height: 2.2rem;
            color: var(--primary);
            display: block;
        }

        .brand .word {
            font-weight: 600;
            font-size: 1.16rem;
            letter-spacing: .22em;
        }

        .site-nav {
            display: none;
            margin-inline-start: auto;
            gap: 2rem;
        }

        @media (min-width: 56rem) {
            .site-nav {
                display: flex;
            }
        }

        .site-nav a {
            text-decoration: none;
            color: var(--muted);
            font-size: .95rem;
            padding-block: .2rem;
        }

        .site-nav a:hover {
            color: var(--fg);
        }

        .site-nav a[aria-current] {
            color: var(--fg);
            border-bottom: 2px solid var(--primary);
        }

        .header-actions {
            margin-inline-start: auto;
            display: flex;
            align-items: center;
            gap: .7rem;
        }

        @media (min-width: 56rem) {
            .header-actions {
                margin-inline-start: 2rem;
            }
        }

        .lang {
            text-decoration: none;
            color: var(--muted);
            font-size: .88rem;
            border: 1px solid var(--rule);
            border-radius: 999px;
            padding: .3rem .8rem;
        }

        .lang:hover {
            color: var(--fg);
            border-color: var(--soft);
        }

        .pill {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            text-decoration: none;
            background: var(--primary);
            color: var(--on-primary);
            font-size: .92rem;
            font-weight: 550;
            padding: .6rem 1.35rem;
            border-radius: 999px;
            border: 0;
            cursor: pointer;
            white-space: nowrap;
        }


        .pill:hover {
            filter: brightness(1.15);
        }

        /* ---- store badge -------------------------------------------------------------- */
        .badges {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .85rem 1.1rem;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: .7rem;
            text-decoration: none;
            background: var(--primary);
            color: var(--on-primary);
            border-radius: .8rem;
            padding: .62rem 1.25rem;
            line-height: 1.2;
            transition: filter .15s ease;
        }

        .badge:hover {
            filter: brightness(1.06);
        }

        /* No dark-mode override: --primary and --on-primary already swap through the palette
           (terracotta on white becomes #FFB4A8 on dark ink), so pinning a colour here would
           fight the tokens rather than follow them. */

        .badge svg {
            width: 1.6rem;
            height: 1.6rem;
            flex: none;
        }

        .badge small {
            display: block;
            font-size: .62rem;
            opacity: .8;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .badge strong {
            display: block;
            font-size: 1rem;
            font-weight: 600;
        }

        /* Not a link: the listing is not public yet, and a badge that goes nowhere is worse
           than one that says so. */
        .badge--soon {
            background: transparent;
            color: var(--muted);
            border: 1px dashed var(--rule);
            cursor: default;
        }

        .badge--soon:hover {
            filter: none;
        }

        .cta-note {
            color: var(--soft);
            font-size: .88rem;
        }

        /* ---- hero --------------------------------------------------------------------- */
        .hero {
            padding-block: 3rem 4rem;
            overflow: hidden;
        }

        .hero .wrap {
            display: grid;
            gap: 3.5rem;
            align-items: center;
        }

        @media (min-width: 62rem) {
            .hero {
                padding-block: 4.5rem 6rem;
            }

            .hero .wrap {
                grid-template-columns: 1fr 1fr;
                gap: 4rem;
            }
        }

        h1 {
            font-size: clamp(2.35rem, 5.6vw, 3.7rem);
            margin: 0 0 1.35rem;
            text-wrap: balance;
        }

        h1 span {
            display: block;
        }

        .lede {
            font-size: 1.06rem;
            color: var(--muted);
            margin: 0 0 2.1rem;
            max-width: 33rem;
        }

        /* ---- hero device -------------------------------------------------------------- */
        .stage {
            position: relative;
            display: flex;
            justify-content: center;
            align-items: center;
            isolation: isolate;
        }

        /* The engravings, thrown out of focus behind everything else. Blurred on purpose: they
           are twelve-thousand-year-old pictures of people, and at full sharpness they compete
           with the phone for attention instead of sitting behind it. Feathered to nothing at the
           edges so the picture dissolves into the page rather than ending on a rectangle.
           The negative inset gives the blur room to bleed without exposing a soft border. */
        .stage::after {
            content: "";
            position: absolute;
            inset: -20% -5%;
            z-index: 0;
            background-image: var(--engrave-photo, none);
            background-size: contain;
            background-position: bottom;
            filter: blur(1px) saturate(1.55);
            /* Kept alongside the unprefixed property: Safari only dropped the -webkit- form in
               15.4, and without it the wall ends on a hard rectangle there instead of fading. */
            -webkit-mask-image: radial-gradient(65% 53% at 49% 60%, #000 0%, transparent 76%);
            mask-image: radial-gradient(65% 53% at 49% 60%, #000 0%, transparent 76%);
            opacity: .75;
        }

        /* The disc behind the phone, and the one sharp photograph in the hero: the arch, framed
           by the circle. A warm shape on its own when no photograph has been added. */
        .stage::before {
            content: "";
            position: absolute;
            width: min(26rem, 92%);
            aspect-ratio: 1;
            z-index: 1;
            background-color: var(--surface-3);
            background-image: var(--hero-photo, none);
            background-size: cover;
            background-position: center;
            border-radius: 50%;
            inset-block-start: 6%;
        }

        .device-shot {
            position: relative;
            z-index: 2;
            width: min(17.5rem, 78%);
            height: auto;
            display: block;
            /* The bezel in the image has its own rounding; the shadow has to follow it or the
               phone looks like it is sitting on a rectangular card. */
            border-radius: 2.1rem;
            filter: drop-shadow(0 24px 48px rgba(88, 52, 40, .34));
        }

        @media (prefers-color-scheme: dark) {
            .device-shot {
                filter: drop-shadow(0 24px 48px rgba(0, 0, 0, .7));
            }
        }

        .device {
            position: relative;
            width: min(17.5rem, 78%);
            aspect-ratio: 9 / 19;
            background: #16110E;
            border-radius: 2.4rem;
            padding: .42rem;
            box-shadow: var(--shadow);
            z-index: 2;
        }

        .device-screen {
            position: relative;
            height: 100%;
            border-radius: 2.05rem;
            overflow: hidden;
            /* Rock face, as a gradient. Replaceable with a photograph without touching markup. */
            background:
                radial-gradient(120% 80% at 20% 12%, rgba(154, 82, 55, .92) 0%, transparent 60%),
                radial-gradient(90% 70% at 85% 78%, rgba(110, 50, 32, .92) 0%, transparent 65%),
                linear-gradient(168deg, rgba(138, 70, 47, .88) 0%, rgba(122, 58, 38, .9) 55%, rgba(107, 47, 31, .94) 100%),
                var(--hero-photo, none) center / cover;
            background-color: #7A3A26;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 3.2rem 1.4rem 2rem;
            color: #F7E7DC;
        }

        .device-antelope {
            width: 3.4rem;
            height: 2.6rem;
            color: #F7E7DC;
            opacity: .95;
        }

        .device-word {
            font-size: 1.32rem;
            letter-spacing: .26em;
            margin: .9rem 0 .35rem;
            font-weight: 600;
        }

        .device-tag {
            font-size: .78rem;
            opacity: .82;
            margin: 0;
        }

        .device-dancers {
            margin-top: auto;
            width: 100%;
            color: #2E1109;
            opacity: .55;
        }

        .device-notch {
            position: absolute;
            inset-inline: 0;
            margin-inline: auto;
            top: .55rem;
            width: 42%;
            height: 1.2rem;
            background: #16110E;
            border-radius: 999px;
            z-index: 2;
        }

        .stage-note {
            display: none;
            position: absolute;
            inset-inline-end: 0;
            top: 26%;
            max-width: 8.5rem;
            color: var(--soft);
            font-size: .92rem;
            font-style: italic;
            line-height: 1.5;
        }

        .stage-note::after {
            content: "";
            display: block;
            width: 2.5rem;
            height: 1px;
            background: var(--rule);
            margin-top: .9rem;
        }

        /* The note only appears where there is room beside the device for it. The padding is what
           makes that room: the device centres inside what is left, instead of colliding with it. */
        @media (min-width: 78rem) {
            .stage-note {
                display: block;
                max-width: 7.75rem;
            }

            .stage {
                padding-inline-end: 9.5rem;
            }
        }

        /* ---- pillars ------------------------------------------------------------------ */
        .pillars {
            background: var(--surface-2);
            border-block: 1px solid var(--rule);
            padding-block: 3.6rem;
        }

        .pillar-grid {
            display: grid;
            gap: 2.5rem 1.5rem;
            text-align: center;
        }

        @media (min-width: 40rem) {
            .pillar-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (min-width: 64rem) {
            .pillar-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        .pillar-icon {
            width: 3.6rem;
            height: 3.6rem;
            margin: 0 auto 1.15rem;
            border-radius: 50%;
            background: var(--surface-3);
            display: grid;
            place-items: center;
            color: var(--ink);
        }

        @media (prefers-color-scheme: dark) {
            .pillar-icon {
                color: var(--fg);
            }
        }

        .pillar-icon svg {
            width: 1.55rem;
            height: 1.55rem;
        }

        .pillar h3 {
            font-size: 1.22rem;
            margin: 0 0 .5rem;
        }

        .pillar p {
            margin: 0 auto;
            color: var(--muted);
            font-size: .95rem;
            max-width: 15rem;
        }

        /* ---- diary -------------------------------------------------------------------- */
        .diary {
            padding-block: 4.5rem;
        }

        .diary .wrap {
            display: grid;
            gap: 3.5rem;
            align-items: center;
        }

        @media (min-width: 62rem) {
            .diary .wrap {
                grid-template-columns: 1fr 1fr;
                gap: 4.5rem;
            }
        }

        h2 {
            font-size: clamp(1.85rem, 3.8vw, 2.7rem);
            margin: 0 0 1.25rem;
            text-wrap: balance;
        }

        h2 span {
            display: block;
        }

        .diary p {
            color: var(--muted);
            margin: 0 0 2rem;
            max-width: 32rem;
        }

        .checks {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: .95rem;
        }

        .checks li {
            display: flex;
            align-items: center;
            gap: .85rem;
            font-size: .98rem;
        }

        .checks svg {
            width: 1.35rem;
            height: 1.35rem;
            color: var(--primary);
            flex: none;
        }

        /* Three overlapping devices, as in the reference. Absolutely positioned rather than
           flex + negative margins: the fan needs each phone at a known offset, and margin
           collapsing against transforms put all three almost on top of each other. */
        .cluster {
            position: relative;
            height: 26rem;
        }

        .mini {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 10.75rem;
            aspect-ratio: 9 / 19;
            background: #16110E;
            border-radius: 1.6rem;
            padding: .3rem;
            box-shadow: var(--shadow);
            --fan: 0rem;
            --tilt: 0deg;
            --lift: 1;
            transform: translate(-50%, -50%) translateX(var(--fan)) rotate(var(--tilt)) scale(var(--lift));
        }

        .mini--back {
            --fan: -7.75rem;
            --tilt: -8deg;
            --lift: .86;
            z-index: 1;
        }

        .mini--front {
            --fan: 7.75rem;
            --tilt: 8deg;
            --lift: .86;
            z-index: 2;
        }

        .mini--main {
            z-index: 3;
        }

        /* The fan reads left-to-right; in RTL it has to read right-to-left or the "front" phone
           lands behind the reading order. */
        [dir="rtl"] .mini--back {
            --fan: 7.75rem;
            --tilt: 8deg;
        }

        [dir="rtl"] .mini--front {
            --fan: -7.75rem;
            --tilt: -8deg;
        }

        .mini-shot {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 10.75rem;
            height: auto;
            display: block;
            border-radius: 1.5rem;
            --fan: 0rem;
            --tilt: 0deg;
            --lift: 1;
            transform: translate(-50%, -50%) translateX(var(--fan)) rotate(var(--tilt)) scale(var(--lift));
            filter: drop-shadow(0 18px 36px rgba(88, 52, 40, .3));
        }

        @media (prefers-color-scheme: dark) {
            .mini-shot {
                filter: drop-shadow(0 18px 36px rgba(0, 0, 0, .65));
            }
        }

        .mini-screen {
            height: 100%;
            border-radius: 1.35rem;
            overflow: hidden;
            background: var(--surface);
            display: flex;
            flex-direction: column;
        }

        .mini-bar {
            height: 1.5rem;
            background: var(--surface-3);
            flex: none;
        }

        .mini-body {
            flex: 1;
            padding: .45rem;
            display: flex;
            flex-direction: column;
            gap: .35rem;
        }

        .mini-tile {
            border-radius: .35rem;
            background: var(--surface-3);
            flex: none;
        }

        .mini-tile.tall {
            flex: 1;
        }

        .mini-tile.line {
            height: .3rem;
        }

        .mini-tile.line.short {
            width: 55%;
        }

        .mini-tile.rock {
            flex: 0 0 44%;
            background: linear-gradient(160deg, #B4785C, #7A3A26);
            display: grid;
            place-items: center;
            color: #2E1109;
        }

        .mini-tile.rock svg {
            width: 66%;
            opacity: .5;
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .2rem;
            flex: 1;
        }

        .mini-grid div {
            background: var(--surface-3);
            border-radius: .2rem;
        }

        /* ---- about -------------------------------------------------------------------- */
        .about {
            position: relative;
            background: var(--surface-2);
            border-block: 1px solid var(--rule);
            padding-block: 4.5rem;
            overflow: hidden;
            isolation: isolate;
        }

        /* The engraved wall as texture rather than as a picture.
           mix-blend-mode is what makes it a pattern instead of a photograph pasted behind text:
           the image's own tones interact with whatever the section's background happens to be,
           so it reads as marks *in* the surface. Multiply in light — the ochre figures darken
           the cream the way pigment darkens rock. Screen in dark, where multiplying against a
           near-black surface would leave nothing to see; there the pale sandstone lifts the
           ground and the figures stay as negative space.
           Desaturated and low-contrast on purpose: this sits under body copy, and anything more
           assertive would win an argument it should not be having. */
        .about::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image: var(--engrave-photo, none);
            background-size: cover;
            background-position: center 62%;
            filter: grayscale(.55) contrast(.9);
            mix-blend-mode: multiply;
            opacity: .16;
            /* Fades out before the section edges so the texture never ends on a visible seam
               against the bands above and below. */
            -webkit-mask-image: radial-gradient(120% 88% at 50% 50%, #000 30%, transparent 92%);
            mask-image: radial-gradient(120% 88% at 50% 50%, #000 30%, transparent 92%);
            pointer-events: none;
        }

        @media (prefers-color-scheme: dark) {
            .about::before {
                mix-blend-mode: screen;
                filter: grayscale(.7) contrast(.85);
                opacity: .12;
            }
        }

        .about .wrap {
            position: relative;
            z-index: 1;
            max-width: 46rem;
        }

        .about p {
            color: var(--muted);
            margin: 0 0 1.5rem;
        }

        .about a {
            color: var(--primary);
            font-weight: 550;
        }

        /* ---- closing ------------------------------------------------------------------ */
        .closing {
            position: relative;
            padding-block: 5.5rem;
            text-align: center;
            overflow: hidden;
        }

        .ridges {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
        }

        /* The photograph sits on top of the drawn ridges. If the file is not there the layer
           simply does not paint and the drawing shows through — no broken image, no empty band. */
        .closing::after {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 1;
            background-image: var(--closing-photo, none);
            background-size: cover;
            background-position: center 62%;
        }

        /* Scrim. A radial wash centred under the headline: opaque enough at the middle to read
           text against, falling away to nothing so the photograph is only really visible down
           the two sides. Uses --bg rather than literal white so it melts into the section above
           and below instead of drawing a pale rectangle across them — and so the dark palette
           gets the same treatment in reverse. */
        .closing::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 2;
            background: radial-gradient(ellipse 50% 150% at 50% 50%,
                    var(--bg) 10%,
                    var(--bg) 20%,
                    color-mix(in srgb, var(--bg) 0%, transparent) 100%)
                /* color-mix(in srgb, var(--bg) 88%, transparent) 72%, */
                /* color-mix(in srgb, var(--bg) 52%, transparent) 88%, */
                /* transparent 100%); */
        }

        /* 
        radial-gradient(ellipse 50% 150% at 50% 50%,
            var(--bg) 10%,
            var(--bg) 20%,
            color-mix(in srgb, var(--bg) 0%, transparent) 100%)  */
        .closing .wrap {
            position: relative;
            z-index: 3;
        }

        .closing h2 {
            color: var(--ink);
        }

        .closing .eyebrow {
            color: color-mix(in srgb, var(--ink) 72%, transparent);
        }

        .closing p {
            color: color-mix(in srgb, var(--ink) 80%, transparent);
            margin: 0 0 2rem;
        }

        .closing .badges {
            justify-content: center;
        }

        @media (prefers-color-scheme: dark) {

            .closing h2,
            .closing .eyebrow,
            .closing p {
                color: var(--fg);
            }
        }

        /* ---- footer ------------------------------------------------------------------- */
        footer {
            border-top: 1px solid var(--rule);
            padding-block: 2rem 2.75rem;
        }

        footer .wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem 2rem;
            align-items: center;
            justify-content: space-between;
        }

        .foot-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
        }

        .foot-brand .mark {
            width: 1.4rem;
            height: 1.6rem;
            color: var(--primary);
            display: block;
        }

        .foot-brand .word {
            font-weight: 600;
            letter-spacing: .2em;
            font-size: .95rem;
        }

        .foot-tagline {
            color: var(--soft);
            font-size: .88rem;
        }

        .foot-links {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem 1.35rem;
        }

        .foot-links a {
            color: var(--soft);
            text-decoration: none;
            font-size: .88rem;
        }

        .photo-credit {
            flex-basis: 100%;
            margin: .25rem 0 0;
            color: var(--soft);
            font-size: .78rem;
        }

        .photo-credit a {
            color: inherit;
        }

        .foot-links a:hover {
            color: var(--fg);
            text-decoration: underline;
        }



        @media (prefers-reduced-motion: reduce) {
            * {
                scroll-behavior: auto !important;
            }
        }

        html {
            scroll-behavior: smooth;
        }
    </style>
</head>

{{-- Declared once, here, rather than on each section: the engravings are used twice (behind the
hero and as the texture under "Where the name comes from") and a custom property set on one
section is invisible to the other. Each only exists when its file does. --}}

<body
    style="@if ($heroPhoto) --hero-photo: url('{{ $heroPhoto }}'); @endif @if ($engravePhoto) --engrave-photo: url('{{ $engravePhoto }}'); @endif @if ($closingPhoto) --closing-photo: url('{{ $closingPhoto }}'); @endif">

    <header class="site-header">
        <div class="wrap">
            <a class="brand" href="{{ url('/?lang=' . $locale) }}">
                <span class="mark">@include('partials.akukas-mark')</span>
                <span class="word">{{ Str::upper($brand) }}</span>
            </a>

            <nav class="site-nav">
                <a href="#top" aria-current="page">{{ $t['nav_home'] }}</a>
                <a href="#features">{{ $t['nav_features'] }}</a>
                <a href="#about">{{ $t['nav_about'] }}</a>
            </nav>

            <div class="header-actions">
                <a class="lang" href="{{ url('/?lang=' . $otherLocale) }}"
                    hreflang="{{ $otherLocale }}">{{ $t['lang_switch'] }}</a>
                <a class="pill" href="#get">{{ $t['nav_download'] }}</a>
            </div>
        </div>
    </header>

    <main id="top">
        <section class="hero">
            <div class="wrap">
                <div>
                    <p class="eyebrow">
                        @foreach ($t['hero_eyebrow'] as $i => $word)
                            @if ($i > 0)<span class="dot">&middot;</span>@endif{{ $word }}
                        @endforeach
                    </p>
                    <h1 class="display"><span>{{ $t['hero_h1_a'] }}</span><span>{{ $t['hero_h1_b'] }}</span></h1>
                    <p class="lede">{{ $t['hero_p'] }}</p>

                    <div class="badges" id="get">
                        @include('partials.play-badge', ['t' => $t, 'playUrl' => $playUrl])
                        <span class="cta-note">{{ $t['cta_note'] }}</span>
                    </div>
                </div>

                {{-- Two photographs, two jobs: the arch is the sharp picture inside the disc, the
                engravings are the soft wash spreading behind it. Both are set here rather
                than in the stylesheet so each only exists when its file does. --}}
                <div class="stage">
                    @if ($heroShot)
                        {{-- The capture already contains its own device bezel, so it is shown as a
                        picture rather than dropped inside the drawn frame below. --}}
                        <img class="device-shot" src="{{ $heroShot }}" alt="{{ $brand }}" width="800" height="1633"
                            fetchpriority="high" decoding="async">
                    @else
                        <div class="device">
                            <div class="device-notch"></div>
                            <div class="device-screen">
                                <span class="device-antelope">@include('partials.rock-art', ['figure' => 'antelope'])</span>
                                <p class="device-word">{{ Str::upper($brand) }}</p>
                                <p class="device-tag">{{ $t['phone_tagline'] }}</p>
                                <span class="device-dancers">@include('partials.rock-art', ['figure' => 'dancers'])</span>
                            </div>
                        </div>
                    @endif
                    <p class="stage-note">{{ $t['hero_note'] }}</p>
                </div>
            </div>
        </section>

        <section class="pillars" id="features">
            <div class="wrap">
                <div class="pillar-grid">
                    @foreach ($t['pillars'] as [$icon, $heading, $body])
                        <div class="pillar">
                            <div class="pillar-icon">@include('partials.pillar-icon', ['icon' => $icon])</div>
                            <h3 class="display">{{ $heading }}</h3>
                            <p>{{ $body }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="about" id="about">
            <div class="wrap">
                <p class="eyebrow">{{ $t['about_eyebrow'] }}</p>
                <h2 class="display">{{ $t['about_h2'] }}</h2>
                <p>{{ $t['about_p'] }}</p>
                <a href="{{ route('legal.show', ['document' => 'privacy', 'locale' => $locale]) }}">{{ $t['about_link'] }}
                    &rarr;</a>
            </div>
        </section>

        <section class="diary">
            <div class="wrap">
                {{-- Real captures where they exist, drawn placeholders otherwise. The captures
                already include a device bezel, so they are not wrapped in .mini's frame —
                only positioned by the same fan. --}}
                <div class="cluster" aria-hidden="true">
                    @if (count($clusterShots) >= 3)
                        @foreach (['back', 'main', 'front'] as $i => $slot)
                            <img class="mini-shot mini--{{ $slot }}" src="{{ $clusterShots[$i] }}" alt="" width="800"
                                height="1633" loading="lazy" decoding="async">
                        @endforeach
                    @else
                        <div class="mini mini--back">
                            <div class="mini-screen">
                                <div class="mini-bar"></div>
                                <div class="mini-body">
                                    <div class="mini-tile rock">@include('partials.rock-art', ['figure' => 'antelope'])
                                    </div>
                                    <div class="mini-tile line"></div>
                                    <div class="mini-tile line short"></div>
                                </div>
                            </div>
                        </div>
                        <div class="mini mini--main">
                            <div class="mini-screen">
                                <div class="mini-bar"></div>
                                <div class="mini-body">
                                    <div class="mini-tile rock">@include('partials.rock-art', ['figure' => 'dancers'])</div>
                                    <div class="mini-tile line"></div>
                                    <div class="mini-tile line short"></div>
                                    <div class="mini-tile tall"></div>
                                </div>
                            </div>
                        </div>
                        <div class="mini mini--front">
                            <div class="mini-screen">
                                <div class="mini-bar"></div>
                                <div class="mini-body">
                                    <div class="mini-grid">
                                        @for ($i = 0; $i < 9; $i++)
                                        <div></div>@endfor
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                <div>
                    <p class="eyebrow">{{ $t['diary_eyebrow'] }}</p>
                    <h2 class="display"><span>{{ $t['diary_h2_a'] }}</span><span>{{ $t['diary_h2_b'] }}</span></h2>
                    <p>{{ $t['diary_p'] }}</p>
                    <ul class="checks">
                        @foreach ($t['diary_list'] as $item)
                            <li>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                    aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" />
                                    <path d="m8.5 12.2 2.4 2.4 4.6-5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                {{ $item }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </section>


        <section class="closing">
            @include('partials.desert-ridges')
            <div class="wrap">
                <p class="eyebrow">{{ $t['closing_eyebrow'] }}</p>
                <h2 class="display">{{ $t['closing_h2'] }}</h2>
                <p>{{ $t['closing_p'] }}</p>
                <div class="badges">
                    @include('partials.play-badge', ['t' => $t, 'playUrl' => $playUrl])
                </div>
            </div>
        </section>
    </main>

    <footer>
        <div class="wrap">
            <div class="foot-brand">
                <span class="mark">@include('partials.akukas-mark')</span>
                <span class="word">{{ Str::upper($brand) }}</span>
            </div>
            <p class="foot-tagline">{{ $t['foot_tagline'][0] }} &nbsp;&middot;&nbsp; {{ $t['foot_tagline'][1] }}</p>
            <nav class="foot-links">
                <a
                    href="{{ route('legal.show', ['document' => 'privacy', 'locale' => $locale]) }}">{{ $t['footer_privacy'] }}</a>
                <a
                    href="{{ route('legal.show', ['document' => 'terms', 'locale' => $locale]) }}">{{ $t['footer_terms'] }}</a>
                <a
                    href="{{ route('legal.show', ['document' => 'csae', 'locale' => $locale]) }}">{{ $t['footer_csae'] }}</a>
                <a
                    href="{{ route('legal.show', ['document' => 'account-deletion', 'locale' => $locale]) }}">{{ $t['footer_delete'] }}</a>
                <a href="mailto:akukasapp@gmail.com">{{ $t['footer_contact'] }}</a>
            </nav>

            @if ($photoCredit)
                {{-- Licence compliance, not decoration: rendered only when a credit is configured
                AND a photograph is actually on the page. config('app.landing_photo_credit')
                is null by default, so nothing shows unless a licence requires it. --}}
                <p class="photo-credit">
                    {{ $t['photo_by'] }}
                    <a href="{{ $photoCredit['url'] }}" rel="noopener nofollow">{{ $photoCredit['author'] }}</a>
                    {{ $t['photo_on'] }} {{ $photoCredit['source'] }}
                </p>
            @endif
        </div>
    </footer>

</body>

</html>