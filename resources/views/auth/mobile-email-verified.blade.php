<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Email verified') }}</title></head>
<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:1.5rem;line-height:1.5">
<h1>{{ __('Email verified') }}</h1>
{{-- SEC-003: the app's akukas://email-verified custom scheme was removed — EmailVerificationActivity
     never read this link's data anyway (it re-checks verification status against the API on
     resume), so no replacement link is needed here; the user just switches back to the app. --}}
<p>{{ __('Your email is verified. You can close this page and return to Akukas.') }}</p>
</body>
</html>
