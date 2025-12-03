<?php

namespace Modules\Billing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Subscription Model
 * 
 * Represents a tenant's billing subscription with plan, user limits, and addons.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $plan (starter|team|enterprise)
 * @property int $user_limit
 * @property array|null $addons
 * @property \Carbon\Carbon|null $next_renewal_at
 * @property string $status (active|canceled|past_due|trialing)
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Subscription extends Model
{
    use HasFactory;

    /**
     * Subscriptions are stored in central database, not tenant databases
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'plan',
        'user_limit',
        'addons',
        'pending_plan',
        'pending_user_limit',
        'pending_plan_effective_at',
        'subscription_start_date',
        'next_renewal_at',
        'status',
        'is_trial',
        'trial_ends_at',
        'billing_period_started_at',
        'billing_period_ends_at',
        'last_renewal_at',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'billing_gateway',
        'failed_renewal_attempts',
        'grace_period_until',
    ];

    protected $casts = [
        'addons' => 'array',
        'subscription_start_date' => 'datetime',
        'next_renewal_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'user_limit' => 'integer',
        'is_trial' => 'boolean',
        'billing_period_started_at' => 'datetime',
        'billing_period_ends_at' => 'datetime',
        'last_renewal_at' => 'datetime',
        'pending_plan_effective_at' => 'datetime',
        'failed_renewal_attempts' => 'integer',
        'grace_period_until' => 'datetime',
    ];

    /**
     * Get the tenant that owns this subscription.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check if subscription is Starter plan.
     */
    public function isStarter(): bool
    {
        return $this->plan === 'starter';
    }

    /**
     * Check if subscription is Team plan.
     */
    public function isTeam(): bool
    {
        return $this->plan === 'team';
    }

    /**
     * Check if subscription is Enterprise plan.
     */
    public function isEnterprise(): bool
    {
        return $this->plan === 'enterprise';
    }

    /**
     * Check if subscription has a specific addon.
     */
    public function hasAddon(string $addon): bool
    {
        return in_array($addon, $this->addons ?? []);
    }

    /**
     * Add an addon to the subscription.
     */
    public function addAddon(string $addon): void
    {
        $addons = $this->addons ?? [];
        
        if (!in_array($addon, $addons)) {
            $addons[] = $addon;
            $this->addons = $addons;
            $this->save();
        }
    }

    /**
     * Remove an addon from the subscription.
     */
    public function removeAddon(string $addon): void
    {
        $addons = $this->addons ?? [];
        $addons = array_values(array_diff($addons, [$addon]));
        
        $this->addons = $addons;
        $this->save();
    }

    /**
     * Check if subscription is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if user limit requires upgrade for Starter plan.
     */
    public function requiresUpgrade(): bool
    {
        return $this->isStarter() && $this->user_limit > 2;
    }

    /**
     * Check if trial is currently active.
     */
    public function isTrialActive(): bool
    {
        return $this->is_trial 
            && $this->trial_ends_at 
            && now()->lt($this->trial_ends_at);
    }

    /**
     * Check if a downgrade is scheduled.
     */
    public function hasPendingDowngrade(): bool
    {
        return !empty($this->pending_plan);
    }

    /**
     * Clear pending downgrade.
     */
    public function clearPendingDowngrade(): void
    {
        $this->pending_plan = null;
        $this->pending_user_limit = null;
        $this->save();
    }
}
