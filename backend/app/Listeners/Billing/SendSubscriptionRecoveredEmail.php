<?php

namespace App\Listeners\Billing;

use App\Events\Billing\SubscriptionRecovered;
use App\Mail\Billing\SubscriptionRecoveredMail;
use App\Services\Email\EmailIdempotencyService;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionRecoveredEmail
{
    public function __construct(private readonly EmailIdempotencyService $idempotency)
    {
    }

    public function handle(SubscriptionRecovered $event): void
    {
        $recipient = $event->tenant->owner_email;
        if (!$recipient) {
            return;
        }

        $periodKey = $event->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';

        $failurePrefix = "subscription:{$event->subscription->id}:payment_failed:period_end:{$periodKey}:attempt:";
        if (!$this->idempotency->existsWithPrefix($failurePrefix)) {
            return;
        }

        $key = "subscription:{$event->subscription->id}:recovered:period_end:{$periodKey}";
        if (!$this->idempotency->acquire($key)) {
            return;
        }

        Mail::to($recipient)->queue(new SubscriptionRecoveredMail(
            tenant: $event->tenant,
            subscription: $event->subscription,
            amount: $event->amount,
        ));
    }
}
