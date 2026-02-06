<x-mail::message>
# Hello {{ $userName }},

Click the button below to sign in to your {{ config('app.name') }} account.

<x-mail::button :url="$url">
Sign In
</x-mail::button>

This link will expire in 15 minutes.

If you did not request this, please ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
