<?php

namespace App\Listeners\Billing;

use App\Events\Billing\SubscriptionExpiredDowngraded;
use App\Mail\Billing\SubscriptionExpiredDowngradedMail;
use App\Services\Email\EmailIdempotencyService;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionExpiredDowngradedEmail
{
    public function __construct(private readonly EmailIdempotencyService $idempotency)
    {
    }

    public function handle(SubscriptionExpiredDowngraded $event): void
    {
        $recipient = $event->tenant->owner_email;
        if (!$recipient) {
            return;
        }

        $periodKey = $event->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $key = "subscription:{$event->subscription->id}:expired:period_end:{$periodKey}";
        if (!$this->idempotency->acquire($key)) {
            return;
        }

        Mail::to($recipient)->queue(new SubscriptionExpiredDowngradedMail(
            tenant: $event->tenant,
            subscription: $event->subscription,
        ));
    }
}
