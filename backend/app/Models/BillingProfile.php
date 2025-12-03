<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BillingProfile
 * 
 * Stores payment gateway information for each tenant (central database).
 * Links tenant to Stripe customer and manages default payment methods.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $gateway
 * @property string|null $stripe_customer_id
 * @property string|null $default_payment_method
 * @property string|null $billing_email
 * @property string|null $billing_name
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * 
 * @property-read Tenant $tenant
 */
class BillingProfile extends Model
{
    /**
     * Billing profiles are stored in central database (NOT tenant databases)
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'gateway',
        'stripe_customer_id',
        'default_payment_method',
        'billing_email',
        'billing_name',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the tenant that owns this billing profile.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Check if tenant has a default payment method configured.
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return !empty($this->default_payment_method);
    }

    /**
     * Check if tenant uses Stripe gateway.
     */
    public function usesStripe(): bool
    {
        return $this->gateway === 'stripe';
    }
}
