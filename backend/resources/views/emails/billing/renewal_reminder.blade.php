<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renewal Reminder</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #0d6efd; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px; }
        .details { background-color: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .button { display: inline-block; background-color: #0d6efd; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Renewal Reminder</h1>
    </div>

    <div class="content">
        <p>Hello {{ $tenant->name }},</p>

        <p>This is a reminder that your <strong>TimePerk {{ ucfirst($subscription->plan) }}</strong> subscription renews in <strong>{{ $daysRemaining }}</strong> day{{ $daysRemaining === 1 ? '' : 's' }}.</p>

        <div class="details">
            <h3>Subscription Details</h3>
            <p><strong>Plan:</strong> {{ ucfirst($subscription->plan) }}</p>
            <p><strong>Renewal date:</strong> {{ $subscription->billing_period_ends_at?->format('F j, Y') ?? ($subscription->next_renewal_at?->format('F j, Y') ?? 'N/A') }}</p>
        </div>

        <p>If you need to update payment details or manage your plan, you can do so from your billing page.</p>

        <p style="text-align: center;">
            <a href="{{ config('app.frontend_url') }}/billing" class="button">Open Billing</a>
        </p>

        <p>Best regards,<br>The TimePerk Team</p>
    </div>

    <div class="footer">
        <p>This is an automated message from TimePerk. Please do not reply to this email.</p>
        <p>© {{ now()->year }} TimePerk. All rights reserved.</p>
    </div>
</body>
</html>
