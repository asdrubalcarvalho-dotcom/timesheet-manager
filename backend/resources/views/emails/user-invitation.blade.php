<x-mail::message>
# Welcome to {{ $tenant->name }}!

Hello {{ $invitedUser->name }},

You have been invited to join **{{ $tenant->name }}** by {{ $inviter->name }}.

Click the button below to accept your invitation and get started.

<x-mail::button :url="rtrim(config('app.frontend_url'), '/') . '/accept-invitation?token=' . $invitedUser->email">
Accept Invitation
</x-mail::button>

If you have any questions, feel free to reach out to your team.

Thanks,<br>
The {{ $tenant->name }} Team
</x-mail::message>
