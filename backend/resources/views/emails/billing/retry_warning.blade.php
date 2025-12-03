<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Retry Warning</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #ffc107; color: #000; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px; }
        .warning { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .details { background-color: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .progress { background-color: #e9ecef; height: 30px; border-radius: 5px; margin: 20px 0; position: relative; }
        .progress-bar { background-color: #ffc107; height: 100%; border-radius: 5px; text-align: center; line-height: 30px; color: #000; font-weight: bold; }
        .button { display: inline-block; background-color: #ffc107; color: #000; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚠️ Payment Retry Attempt {{ $attemptNumber }}/3</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $tenant->name }},</p>
        
        <p>We attempted to process your payment again, but it was unsuccessful.</p>
        
        <div class="warning">
            <strong>Attempt {{ $attemptNumber }} of 3 Failed</strong><br>
            Please update your payment method to restore your subscription.
        </div>
        
        <div class="details">
            <h3>Subscription Status</h3>
            <p><strong>Plan:</strong> {{ ucfirst($subscription->plan) }}</p>
            <p><strong>Status:</strong> Past Due</p>
            <p><strong>Failed Attempts:</strong> {{ $attemptNumber }} / 3</p>
            <p><strong>Grace Period Ends:</strong> {{ $subscription->grace_period_until?->format('F j, Y g:i A') ?? 'N/A' }}</p>
        </div>
        
        <div class="progress">
            <div class="progress-bar" style="width: {{ ($attemptNumber / 3) * 100 }}%">
                {{ $attemptNumber }}/3 Attempts
            </div>
        </div>
        
        @if ($attemptNumber < 3)
            <p><strong>Next Steps:</strong></p>
            <ul>
                <li>We will automatically retry in <strong>{{ $attemptNumber === 1 ? '3' : '7' }} days</strong></li>
                <li>Update your payment method now to restore service immediately</li>
                <li>Contact support if you need assistance</li>
            </ul>
        @else
            <p><strong>⚠️ Final Warning:</strong></p>
            <ul>
                <li>This was the <strong>final automatic retry attempt</strong></li>
                <li>Your grace period ends on <strong>{{ $subscription->grace_period_until?->format('F j, Y') }}</strong></li>
                <li>After this date, your subscription will be <strong>automatically canceled</strong></li>
                <li>You will be downgraded to the <strong>Free Starter plan</strong></li>
            </ul>
        @endif
        
        <p style="text-align: center;">
            <a href="{{ config('app.frontend_url') }}/billing" class="button">Update Payment Method Now</a>
        </p>
        
        <p>If you have any questions, please contact our support team immediately.</p>
        
        <p>Best regards,<br>The TimePerk Team</p>
    </div>
    
    <div class="footer">
        <p>This is an automated message from TimePerk. Please do not reply to this email.</p>
        <p>© {{ now()->year }} TimePerk. All rights reserved.</p>
    </div>
</body>
</html>
