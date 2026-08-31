<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Reset password') }}</title></head>
<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:1.5rem;line-height:1.5">
<h1>{{ __('Reset your password') }}</h1>
<p>{{ __('Continue in Akukas to choose a new password.') }}</p>
<p><a href="akukas://reset-password?token={{ rawurlencode($token) }}&amp;email={{ rawurlencode($email) }}">{{ __('Open Akukas') }}</a></p>
</body>
</html>
