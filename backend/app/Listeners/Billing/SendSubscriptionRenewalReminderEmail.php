<?php

namespace App\Listeners\Billing;

use App\Events\Billing\SubscriptionRenewalReminderDue;
use App\Mail\Billing\SubscriptionRenewalReminderMail;
use App\Services\Email\EmailIdempotencyService;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionRenewalReminderEmail
{
    public function __construct(private readonly EmailIdempotencyService $idempotency)
    {
    }

    public function handle(SubscriptionRenewalReminderDue $event): void
    {
        $recipient = $event->tenant->owner_email;
        if (!$recipient) {
            return;
        }

        $periodKey = $event->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $key = "subscription:{$event->subscription->id}:renewal:period_end:{$periodKey}";
        if (!$this->idempotency->acquire($key)) {
            return;
        }

        Mail::to($recipient)->queue(new SubscriptionRenewalReminderMail(
            tenant: $event->tenant,
            subscription: $event->subscription,
            daysRemaining: $event->daysRemaining,
        ));
    }
}
