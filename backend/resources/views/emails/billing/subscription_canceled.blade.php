<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Subscription Canceled</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #6c757d; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f8f9fa; padding: 30px; border-radius: 0 0 5px 5px; }
        .alert { background-color: #f8d7da; border-left: 4px solid: #dc3545; padding: 15px; margin: 20px 0; color: #721c24; }
        .details { background-color: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .comparison { display: flex; justify-content: space-between; margin: 20px 0; }
        .plan-box { flex: 1; margin: 0 10px; padding: 15px; border-radius: 5px; text-align: center; }
        .old-plan { background-color: #f8d7da; border: 2px solid #dc3545; }
        .new-plan { background-color: #d4edda; border: 2px solid #28a745; }
        .button { display: inline-block; background-color: #28a745; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Subscription Canceled</h1>
    </div>
    
    <div class="content">
        <p>Hello {{ $tenant->name }},</p>
        
        <p>We're sorry to inform you that your <strong>TimePerk {{ ucfirst($subscription->plan) }}</strong> subscription has been canceled due to failed payment attempts.</p>
        
        <div class="alert">
            <strong>Your account has been downgraded</strong><br>
            You are now on the <strong>Free Starter Plan</strong> with limited features.
        </div>
        
        <div class="details">
            <h3>Cancellation Summary</h3>
            <p><strong>Previous Plan:</strong> {{ ucfirst($subscription->plan) }}</p>
            <p><strong>Failed Payment Attempts:</strong> {{ $subscription->failed_renewal_attempts }}</p>
            <p><strong>Grace Period Ended:</strong> {{ $subscription->grace_period_until?->format('F j, Y') ?? 'N/A' }}</p>
            <p><strong>Canceled on:</strong> {{ now()->format('F j, Y') }}</p>
        </div>
        
        <div class="comparison">
            <div class="plan-box old-plan">
                <h4>❌ Previous Plan</h4>
                <p><strong>{{ ucfirst($subscription->plan) }}</strong></p>
                <p>{{ $subscription->user_limit }} users</p>
                <p>All premium features</p>
            </div>
            <div class="plan-box new-plan">
                <h4>✅ Current Plan</h4>
                <p><strong>Starter (Free)</strong></p>
                <p>2 users max</p>
                <p>Basic features only</p>
            </div>
        </div>
        
        <p><strong>What this means for you:</strong></p>
        <ul>
            <li>Your user limit has been reduced to <strong>2 users</strong></li>
            <li>Premium features (Planning, AI, Travels) are now <strong>disabled</strong></li>
            <li>Basic features (Timesheets, Expenses) remain <strong>available</strong></li>
            <li>All your data is <strong>safe and preserved</strong></li>
        </ul>
        
        <p><strong>Want to restore your subscription?</strong></p>
        <p>You can reactivate your premium plan at any time by updating your payment method and upgrading.</p>
        
        <p style="text-align: center;">
            <a href="{{ config('app.frontend_url') }}/billing/upgrade" class="button">Reactivate Premium Plan</a>
        </p>
        
        <p>If you have any questions or need assistance, please don't hesitate to contact our support team.</p>
        
        <p>We hope to see you back soon!</p>
        
        <p>Best regards,<br>The TimePerk Team</p>
    </div>
    
    <div class="footer">
        <p>This is an automated message from TimePerk. Please do not reply to this email.</p>
        <p>© {{ now()->year }} TimePerk. All rights reserved.</p>
    </div>
</body>
</html>
