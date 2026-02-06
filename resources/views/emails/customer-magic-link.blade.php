<x-mail::message>
# Hello {{ $customerName }},

Click the button below to view your support tickets.

<x-mail::button :url="$url">
View My Tickets
</x-mail::button>

This link will expire in 15 minutes.

If you did not request this, please ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
