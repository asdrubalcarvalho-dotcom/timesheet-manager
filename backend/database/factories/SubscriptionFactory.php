<?php

namespace Database\Factories;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::ulid(),
            'plan' => 'starter',
            'user_limit' => 2,
            'addons' => [],
            'status' => 'active',
            'is_trial' => false,
            'trial_ends_at' => null,
            'next_renewal_at' => null,
        ];
    }
}
