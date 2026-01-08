<?php

namespace App\Events\Billing;

use App\Models\Tenant;
use Modules\Billing\Models\Subscription;

class SubscriptionRecovered
{
    public function __construct(
        public Tenant $tenant,
        public Subscription $subscription,
        public float $amount,
    ) {
    }
}
