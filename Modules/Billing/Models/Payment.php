<?php

namespace Modules\Billing\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment Model
 * 
 * Represents a payment transaction for a tenant subscription.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property float $amount
 * @property string $currency
 * @property string $status (pending|completed|failed|refunded)
 * @property string $gateway (fake_card|stripe|paypal)
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class Payment extends Model
{
    use HasFactory;

    /**
     * Payments are stored in central database, not tenant databases
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'operation',
        'amount',
        'currency',
        'status',
        'gateway',
        'payment_method',
        'transaction_id',
        'gateway_reference',
        'metadata',
        'notes',
        'paid_at',
        'completed_at',
        // Snapshot fields
        'plan',
        'user_count',
        'addons',
        'cycle_start',
        'cycle_end',
        'stripe_payment_intent_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'completed_at' => 'datetime',
        'addons' => 'array',
        'cycle_start' => 'datetime',
        'cycle_end' => 'datetime',
    ];

    /**
     * Get the tenant that owns this payment.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check if payment is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if payment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if payment is paid (alias for isCompleted).
     * Used by PaymentSnapshot service.
     */
    public function isPaid(): bool
    {
        return $this->isCompleted();
    }

    /**
     * Check if payment failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Mark payment as completed.
     */
    public function markCompleted(): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Mark payment as paid (alias for markCompleted).
     * Used by PaymentSnapshot service.
     */
    public function markAsPaid(): void
    {
        $this->markCompleted();
    }

    /**
     * Mark payment as failed.
     */
    public function markFailed(string $reason = null): void
    {
        $this->status = 'failed';
        
        if ($reason) {
            $metadata = $this->metadata ?? [];
            $metadata['failure_reason'] = $reason;
            $this->metadata = $metadata;
        }
        
        $this->save();
    }
}
