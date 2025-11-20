<?php

namespace Modules\Billing\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantLicense Model
 * 
 * Manages license/seat allocation for tenants.
 * Integrates with Stripe Cashier for billing.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property int $purchased_licenses
 * @property int $used_licenses
 * @property float $price_per_license
 * @property string $billing_cycle
 * @property string|null $stripe_subscription_id
 * @property string|null $stripe_price_id
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property bool $auto_upgrade
 * @property array|null $metadata
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TenantLicense extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'tenant_id',
        'purchased_licenses',
        'used_licenses',
        'price_per_license',
        'billing_cycle',
        'stripe_subscription_id',
        'stripe_price_id',
        'trial_ends_at',
        'auto_upgrade',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'purchased_licenses' => 'integer',
        'used_licenses' => 'integer',
        'price_per_license' => 'decimal:2',
        'trial_ends_at' => 'datetime',
        'auto_upgrade' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Billing cycles
     */
    public const BILLING_CYCLES = [
        'monthly' => 'Monthly',
        'annual' => 'Annual',
    ];

    /**
     * Get the tenant that owns this license
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Get the user who created this record
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this record
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get number of available (unused) licenses
     */
    public function availableLicenses(): int
    {
        return max(0, $this->purchased_licenses - $this->used_licenses);
    }

    /**
     * Check if there are available licenses
     */
    public function hasAvailableLicenses(): bool
    {
        return $this->availableLicenses() > 0;
    }

    /**
     * Check if tenant can add a new user
     */
    public function canAddUser(): bool
    {
        return $this->hasAvailableLicenses();
    }

    /**
     * Increment purchased licenses
     */
    public function incrementLicenses(int $quantity = 1): bool
    {
        $this->purchased_licenses += $quantity;
        return $this->save();
    }

    /**
     * Decrement purchased licenses (won't go below used_licenses)
     */
    public function decrementLicenses(int $quantity = 1): bool
    {
        $newTotal = $this->purchased_licenses - $quantity;
        
        // Can't go below current usage
        if ($newTotal < $this->used_licenses) {
            throw new \Exception('Cannot remove licenses: would exceed current usage');
        }

        $this->purchased_licenses = $newTotal;
        return $this->save();
    }

    /**
     * Increment used licenses
     */
    public function incrementUsage(): bool
    {
        if (!$this->canAddUser() && !$this->auto_upgrade) {
            throw new \Exception('No available licenses');
        }

        // Auto-upgrade if enabled and needed
        if (!$this->hasAvailableLicenses() && $this->auto_upgrade) {
            $this->incrementLicenses(1);
        }

        $this->used_licenses += 1;
        return $this->save();
    }

    /**
     * Decrement used licenses
     */
    public function decrementUsage(): bool
    {
        if ($this->used_licenses > 0) {
            $this->used_licenses -= 1;
            return $this->save();
        }

        return false;
    }

    /**
     * Get utilization percentage
     */
    public function utilizationPercentage(): float
    {
        if ($this->purchased_licenses === 0) {
            return 0;
        }

        return round(($this->used_licenses / $this->purchased_licenses) * 100, 2);
    }

    /**
     * Check if in trial period
     */
    public function isTrialing(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Get monthly cost
     */
    public function monthlyCost(): float
    {
        $cost = $this->purchased_licenses * $this->price_per_license;

        if ($this->billing_cycle === 'annual') {
            $cost = $cost / 12; // Convert annual to monthly
        }

        return round($cost, 2);
    }

    /**
     * Get annual cost
     */
    public function annualCost(): float
    {
        $cost = $this->purchased_licenses * $this->price_per_license;

        if ($this->billing_cycle === 'monthly') {
            $cost = $cost * 12;
        }

        return round($cost, 2);
    }

    /**
     * Scope: Tenants with available licenses
     */
    public function scopeWithAvailableLicenses($query)
    {
        return $query->whereColumn('used_licenses', '<', 'purchased_licenses');
    }

    /**
     * Scope: Tenants at capacity
     */
    public function scopeAtCapacity($query)
    {
        return $query->whereColumn('used_licenses', '>=', 'purchased_licenses');
    }

    /**
     * Scope: Trialing tenants
     */
    public function scopeTrialing($query)
    {
        return $query->whereNotNull('trial_ends_at')
                     ->where('trial_ends_at', '>', now());
    }
}
