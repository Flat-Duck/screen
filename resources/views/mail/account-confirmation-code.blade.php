<x-mail::message>
# Your confirmation code

Use this code to confirm the sensitive action you just requested:

<x-mail::panel>
{{ $code }}
</x-mail::panel>

This code expires in 10 minutes. If you didn't request this, you can safely ignore this
email — no action was taken.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
