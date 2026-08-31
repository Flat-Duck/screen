<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Email verified') }}</title></head>
<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:1.5rem;line-height:1.5">
<h1>{{ __('Email verified') }}</h1>
<p>{{ __('Your email is verified. Return to Akukas to continue.') }}</p>
<p><a href="akukas://email-verified">{{ __('Open Akukas') }}</a></p>
</body>
</html>
