<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Reset password') }}</title></head>
<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:1.5rem;line-height:1.5">
<h1>{{ __('Reset your password') }}</h1>
<p>{{ __('Continue in Akukas to choose a new password.') }}</p>
{{-- SEC-002: the app's akukas://reset-password custom scheme was removed — a custom scheme has
     no domain-ownership verification, so any other installed app could register it and receive
     this reset token. This links back to the same verified https App Link URL instead; tapping
     it (a real user gesture, in a browser) is what lets Android offer to open the app directly
     when it's installed, same as the original email link did. --}}
<p><a href="{{ url()->current() }}?email={{ rawurlencode($email) }}">{{ __('Open Akukas') }}</a></p>
</body>
</html>
