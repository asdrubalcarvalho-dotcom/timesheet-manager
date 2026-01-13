@component('mail::message')
# Welcome to {{ config('app.name') }}!

You have requested to create a new workspace: **{{ $companyName }}**

To complete your registration, please verify your email address by clicking the button below:

@component('mail::button', ['url' => $verificationUrl])
Verify Email Address
@endcomponent

This verification link will expire in 24 hours.

If you did not create this account, no further action is required. The registration request will automatically expire.

Thanks,
{{ config('app.name') }}
@endcomponent
