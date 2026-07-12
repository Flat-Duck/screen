<x-mail::message>
# Your account email address was changed

The email address on your account was just changed to **{{ $newEmail }}**. Every
device signed in to your account was also signed out as a precaution.

If you made this change, no action is needed.

If you didn't make this change, someone else may have access to your account —
please contact support right away.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
