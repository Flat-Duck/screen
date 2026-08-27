@php
    $titles = [
        'privacy' => ['en' => 'Privacy Policy', 'ar' => 'سياسة الخصوصية'],
        'terms' => ['en' => 'Terms of Service', 'ar' => 'شروط الخدمة'],
        'account-deletion' => ['en' => 'Delete your account', 'ar' => 'حذف الحساب'],
    ];
    $localeNames = ['en' => 'English', 'ar' => 'العربية'];
    $title = $titles[$document][$locale] ?? ucfirst($document);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    {{-- Play's crawler and any link previewer should see a clean description. --}}
    <meta name="description" content="{{ $title }} for {{ config('app.name') }}">
    <style>
        :root { color-scheme: light dark; --fg:#1a1a1a; --muted:#6b6b6b; --bg:#ffffff; --rule:#e5e5e5; --link:#0b5fff; }
        @media (prefers-color-scheme: dark) {
            :root { --fg:#e8e8e8; --muted:#9a9a9a; --bg:#141414; --rule:#2c2c2c; --link:#7aa2ff; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--fg);
            font: 16px/1.7 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Naskh Arabic", "Helvetica Neue", Arial, sans-serif;
        }
        .wrap { max-width: 46rem; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }
        header { border-bottom: 1px solid var(--rule); padding-bottom: 1rem; margin-bottom: 2rem; }
        .row { display: flex; gap: 1rem; align-items: baseline; justify-content: space-between; flex-wrap: wrap; }
        .brand { font-weight: 600; font-size: 1.05rem; }
        main h1 { font-size: 1.9rem; margin: 0 0 .5rem; letter-spacing: -0.01em; line-height: 1.25; }
        .meta { color: var(--muted); font-size: .875rem; margin: 0; }
        nav a { color: var(--link); text-decoration: none; font-size: .875rem; }
        nav a:hover { text-decoration: underline; }
        main :is(h2,h3) { margin-top: 2.25rem; line-height: 1.3; }
        main h2 { font-size: 1.3rem; }
        main h3 { font-size: 1.05rem; }
        main p, main li { color: var(--fg); }
        main ul, main ol { padding-inline-start: 1.4rem; }
        main li + li { margin-top: .35rem; }
        main a { color: var(--link); }
        main table { border-collapse: collapse; width: 100%; display: block; overflow-x: auto; }
        main :is(th,td) { border: 1px solid var(--rule); padding: .5rem .65rem; text-align: start; }
        code { background: color-mix(in srgb, var(--fg) 8%, transparent); padding: .1rem .3rem; border-radius: 3px; }
        footer { margin-top: 3.5rem; padding-top: 1rem; border-top: 1px solid var(--rule); color: var(--muted); font-size: .875rem; }
        footer a { color: var(--link); text-decoration: none; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="row">
            {{-- No <h1> or date here: each document in resources/legal/ opens with its own
                 title and an authoritative "Last Updated" line. Repeating them risks showing a
                 file-mtime date that contradicts the one the document legally states. --}}
            <div class="brand">{{ config('app.name') }}</div>
            <nav>
                @foreach ($otherLocales as $other)
                    <a href="{{ route('legal.show', ['document' => $document, 'locale' => $other]) }}"
                       hreflang="{{ $other }}">{{ $localeNames[$other] ?? $other }}</a>
                @endforeach
            </nav>
        </div>
    </header>

    <main>
        {{-- Author-controlled Markdown from resources/legal/, never user input. --}}
        {!! $html !!}
    </main>

    <footer>
        <a href="{{ route('legal.show', ['document' => 'privacy', 'locale' => $locale]) }}">{{ $titles['privacy'][$locale] }}</a>
        &middot;
        <a href="{{ route('legal.show', ['document' => 'terms', 'locale' => $locale]) }}">{{ $titles['terms'][$locale] }}</a>
        &middot;
        {{ config('app.name') }}
    </footer>
</div>
</body>
</html>
