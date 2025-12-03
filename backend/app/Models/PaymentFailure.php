<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PaymentFailure Model
 * 
 * Phase 4: Payment failure tracking for dunning system
 * 
 * Table: billing_payment_failures (central database)
 * 
 * Purpose:
 * - Track Stripe payment failures
 * - Support intelligent dunning (reminder emails)
 * - Monitor retry attempts and resolution
 * - Link failures to invoices and tenants
 * 
 * Relationships:
 * - belongsTo(Tenant)
 * - belongsTo(BillingInvoice) via stripe_invoice_id
 * 
 * Scopes:
 * - pending() - Unresolved failures
 * - needsReminder() - Due for next dunning email
 * - overdue() - Past auto-pause threshold
 * 
 * Usage:
 * - Populated by webhook handlers
 * - Queried by dunning command
 * - Displayed in admin dashboard
 */
class PaymentFailure extends Model
{
    use HasFactory;

    /**
     * Table in central database (NOT tenant-scoped)
     */
    protected $connection = 'mysql'; // Central database
    protected $table = 'billing_payment_failures';

    /**
     * Mass-assignable attributes
     */
    protected $fillable = [
        'tenant_id',
        'tenant_slug',
        'stripe_invoice_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'reason',
        'error_message',
        'amount',
        'status',
        'failed_at',
        'resolved_at',
        'reminder_count',
        'last_reminder_at',
        'next_reminder_at',
        'resolution_method',
        'notes',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'failed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'next_reminder_at' => 'datetime',
        'reminder_count' => 'integer',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Failure belongs to a tenant
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Failure may be related to an invoice
     */
    public function invoice()
    {
        return $this->belongsTo(BillingInvoice::class, 'stripe_invoice_id', 'stripe_invoice_id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scope: Unresolved payment failures
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'retrying']);
    }

    /**
     * Scope: Failures needing next dunning reminder
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNeedsReminder($query)
    {
        return $query->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('next_reminder_at')
                  ->orWhere('next_reminder_at', '<=', now());
            });
    }

    /**
     * Scope: Failures past auto-pause threshold
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days - Days threshold (default: 21)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query, int $days = 21)
    {
        return $query->where('status', 'pending')
            ->where('failed_at', '<=', now()->subDays($days));
    }

    /**
     * Scope: Filter by status
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPER METHODS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calculate days since payment failed
     * 
     * @return int
     */
    public function getDaysSinceFailureAttribute(): int
    {
        if (!$this->failed_at) {
            return 0;
        }

        return now()->diffInDays($this->failed_at);
    }

    /**
     * Check if failure is overdue for auto-pause
     * 
     * @param int $threshold - Days threshold (default: 21)
     * @return bool
     */
    public function isOverdue(int $threshold = 21): bool
    {
        return $this->status === 'pending' && $this->days_since_failure >= $threshold;
    }

    /**
     * Mark failure as resolved
     * 
     * @param string $method - Resolution method: auto_retry, manual, canceled
     * @param string|null $notes - Optional notes
     * @return bool
     */
    public function markAsResolved(string $method, ?string $notes = null): bool
    {
        return $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolution_method' => $method,
            'notes' => $notes ?? $this->notes,
        ]);
    }

    /**
     * Record reminder email sent
     * 
     * @param int $nextReminderDays - Days until next reminder
     * @return bool
     */
    public function recordReminder(int $nextReminderDays): bool
    {
        return $this->update([
            'reminder_count' => $this->reminder_count + 1,
            'last_reminder_at' => now(),
            'next_reminder_at' => now()->addDays($nextReminderDays),
        ]);
    }

    /**
     * Calculate which dunning stage this failure is in
     * 
     * @return string - 'reminder_1', 'reminder_2', 'final_warning', 'auto_pause'
     */
    public function getDunningStageAttribute(): string
    {
        $days = $this->days_since_failure;
        $stages = config('billing.dunning.days', [
            'reminder_1' => 3,
            'reminder_2' => 7,
            'final_warning' => 14,
            'pause' => 21,
        ]);

        if ($days >= $stages['pause']) {
            return 'auto_pause';
        } elseif ($days >= $stages['final_warning']) {
            return 'final_warning';
        } elseif ($days >= $stages['reminder_2']) {
            return 'reminder_2';
        } elseif ($days >= $stages['reminder_1']) {
            return 'reminder_1';
        }

        return 'pending';
    }

    /**
     * Get human-readable failure reason
     * 
     * @return string
     */
    public function getReadableReasonAttribute(): string
    {
        // Map Stripe reason codes to user-friendly messages
        $reasons = [
            'insufficient_funds' => 'Insufficient funds in account',
            'card_declined' => 'Card was declined',
            'expired_card' => 'Card has expired',
            'incorrect_cvc' => 'Incorrect CVC code',
            'processing_error' => 'Payment processing error',
            'authentication_required' => 'Additional authentication required',
        ];

        return $reasons[$this->reason] ?? ($this->error_message ?? 'Unknown error');
    }
}
