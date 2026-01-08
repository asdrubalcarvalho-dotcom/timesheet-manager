<?php

namespace App\Listeners\Billing;

use App\Events\Billing\SubscriptionPaymentFailed;
use App\Mail\Billing\SubscriptionPaymentFailedMail;
use App\Services\Email\EmailIdempotencyService;
use Illuminate\Support\Facades\Mail;

class SendSubscriptionPaymentFailedEmail
{
    public function __construct(private readonly EmailIdempotencyService $idempotency)
    {
    }

    public function handle(SubscriptionPaymentFailed $event): void
    {
        $recipient = $event->tenant->owner_email;
        if (!$recipient) {
            return;
        }

        $graceUntil = $event->subscription->grace_period_until;
        if ($graceUntil && now()->greaterThan($graceUntil)) {
            return;
        }

        $periodKey = $event->subscription->billing_period_ends_at?->toDateString() ?? 'unknown';
        $attempt = (int) ($event->subscription->failed_renewal_attempts ?? 0);
        if ($attempt < 1) {
            $attempt = 1;
        }
        if ($attempt > 3) {
            return;
        }

        $key = "subscription:{$event->subscription->id}:payment_failed:period_end:{$periodKey}:attempt:{$attempt}";
        if (!$this->idempotency->acquire($key)) {
            return;
        }

        Mail::to($recipient)->queue(new SubscriptionPaymentFailedMail(
            tenant: $event->tenant,
            subscription: $event->subscription,
            amount: $event->amount,
            reason: $event->reason,
        ));
    }
}
