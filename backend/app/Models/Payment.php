<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /**
     * Payment snapshots are stored in the CENTRAL database.
     * They represent billing transactions across all tenants.
     */
    protected $connection = 'mysql';
    
    protected $fillable = [
        'tenant_id',
        'plan',
        'user_count',
        'addons',
        'amount',
        'currency',
        'cycle_start',
        'cycle_end',
        'stripe_payment_intent_id',
        'status',
        'metadata',
    ];

    protected $casts = [
        'addons' => 'array',
        'metadata' => 'array',
        'amount' => 'decimal:2',
        'cycle_start' => 'date',
        'cycle_end' => 'date',
    ];

    /**
     * Get the tenant that owns this payment.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Check if payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark payment as paid.
     */
    public function markAsPaid(): void
    {
        $this->update(['status' => 'paid']);
    }

    /**
     * Mark payment as failed.
     */
    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    /**
     * Get human-readable plan name.
     */
    public function getPlanNameAttribute(): string
    {
        return match($this->plan) {
            'starter' => 'Starter Plan',
            'team' => 'Team Plan',
            'enterprise' => 'Enterprise Plan',
            default => ucfirst($this->plan),
        };
    }

    /**
     * Get formatted amount with currency.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '€' . number_format($this->amount, 2);
    }

    /**
     * Check if addons are enabled.
     */
    public function hasPlanningAddon(): bool
    {
        return in_array('planning', $this->addons ?? []);
    }

    public function hasAiAddon(): bool
    {
        return in_array('ai', $this->addons ?? []);
    }

    /**
     * Scope to filter by tenant.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by billing period.
     */
    public function scopeForPeriod($query, $startDate, $endDate)
    {
        return $query->where('cycle_start', '>=', $startDate)
                    ->where('cycle_end', '<=', $endDate);
    }
}
