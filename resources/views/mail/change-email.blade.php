<x-mail::message>
# Confirm your new email address

Tap the button below to finish changing the email address on your account.

<x-mail::button :url="$verificationUrl">
Confirm email address
</x-mail::button>

This link expires in 60 minutes. If you didn't request this change, you can safely ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
