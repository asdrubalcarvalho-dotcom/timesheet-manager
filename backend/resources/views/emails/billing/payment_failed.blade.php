<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - Action Required</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px; }
        .alert { background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; }
        .details { background-color: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .button { display: inline-block; background-color: #007bff; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚠️ Payment Failed</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $tenant->name }},</p>
        
        <p>We were unable to process your recent payment for <strong>TimePerk</strong>.</p>
        
        <div class="alert">
            <strong>⏰ Immediate Action Required</strong><br>
            Your subscription is now in <strong>past_due</strong> status. Please update your payment method to avoid service interruption.
        </div>
        
        <div class="details">
            <h3>Payment Details</h3>
            <p><strong>Plan:</strong> {{ ucfirst($subscription->plan) }}</p>
            <p><strong>Amount:</strong> €{{ number_format($amount, 2) }}</p>
            <p><strong>Failed on:</strong> {{ now()->format('F j, Y') }}</p>
            <p><strong>Grace period until:</strong> {{ $subscription->grace_period_until?->format('F j, Y') ?? 'N/A' }}</p>
        </div>
        
        <p><strong>What happens next?</strong></p>
        <ul>
            <li>We will automatically retry the payment in <strong>3 days</strong></li>
            <li>You have <strong>7 days</strong> to update your payment method</li>
            <li>After the grace period, your subscription will be <strong>canceled</strong> and downgraded to the Free Starter plan</li>
        </ul>
        
        <p style="text-align: center;">
            <a href="{{ config('app.frontend_url') }}/billing" class="button">Update Payment Method</a>
        </p>
        
        <p>If you believe this is an error or need assistance, please contact our support team.</p>
        
        <p>Best regards,<br>The TimePerk Team</p>
    </div>
    
    <div class="footer">
        <p>This is an automated message from TimePerk. Please do not reply to this email.</p>
        <p>© {{ now()->year }} TimePerk. All rights reserved.</p>
    </div>
</body>
</html>
