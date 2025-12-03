<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * BillingInvoice Model
 * 
 * Phase 3: Local invoice tracking for ERP integration
 * 
 * Table: billing_invoices (central database)
 * 
 * Purpose:
 * - Store Stripe invoice metadata locally
 * - Track PDF URLs for accounting system downloads
 * - Monitor ERP processing deadlines (15-day legal requirement)
 * - Support reconciliation and reporting
 * 
 * Relationships:
 * - belongsTo(Tenant) - Invoice owner
 * 
 * Scopes:
 * - pendingErp() - Invoices awaiting ERP processing
 * - approachingDeadline() - Invoices with deadline < 7 days
 * - byStatus() - Filter by status
 * 
 * Usage:
 * - Populated by StripeWebhookController
 * - Queried by InvoiceSyncService
 * - Displayed in billing admin panel
 */
class BillingInvoice extends Model
{
    use HasFactory;

    /**
     * Table in central database (NOT tenant-scoped)
     */
    protected $connection = 'mysql'; // Central database
    protected $table = 'billing_invoices';

    /**
     * Mass-assignable attributes
     */
    protected $fillable = [
        'stripe_invoice_id',
        'stripe_subscription_id',
        'tenant_id',
        'tenant_slug',
        'status',
        'billing_period_start',
        'billing_period_end',
        'amount_due',
        'amount_paid',
        'currency',
        'pdf_url',
        'erp_processed',
        'erp_processed_at',
        'erp_deadline_at',
        'erp_notes',
        'metadata',
    ];

    /**
     * Attribute casting
     */
    protected $casts = [
        'billing_period_start' => 'datetime',
        'billing_period_end' => 'datetime',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'erp_processed' => 'boolean',
        'erp_processed_at' => 'datetime',
        'erp_deadline_at' => 'datetime',
        'metadata' => 'array',
    ];

    // ─────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Invoice belongs to a tenant
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    // ─────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Scope: Invoices pending ERP processing
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePendingErp($query)
    {
        return $query->where('erp_processed', false)
            ->whereIn('status', ['open', 'paid']);
    }

    /**
     * Scope: Invoices with approaching deadline
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days - Days before deadline (default: 7)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproachingDeadline($query, int $days = 7)
    {
        return $query->where('erp_processed', false)
            ->whereNotNull('erp_deadline_at')
            ->where('erp_deadline_at', '<=', now()->addDays($days));
    }

    /**
     * Scope: Filter by status
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $status - draft, open, paid, uncollectible, void
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope: Overdue ERP processing (past deadline)
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOverdue($query)
    {
        return $query->where('erp_processed', false)
            ->whereNotNull('erp_deadline_at')
            ->where('erp_deadline_at', '<', now());
    }

    // ─────────────────────────────────────────────────────────────────────
    // HELPER METHODS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Calculate days left until ERP deadline
     * 
     * @return int|null - Days remaining (negative if overdue, null if no deadline)
     */
    public function getDaysUntilDeadlineAttribute(): ?int
    {
        if (!$this->erp_deadline_at) {
            return null;
        }

        return now()->diffInDays($this->erp_deadline_at, false);
    }

    /**
     * Check if invoice is overdue for ERP processing
     * 
     * @return bool
     */
    public function isOverdue(): bool
    {
        if ($this->erp_processed || !$this->erp_deadline_at) {
            return false;
        }

        return $this->erp_deadline_at->isPast();
    }

    /**
     * Mark invoice as processed by ERP
     * 
     * @param string|null $notes - Optional processing notes
     * @return bool
     */
    public function markAsProcessed(?string $notes = null): bool
    {
        return $this->update([
            'erp_processed' => true,
            'erp_processed_at' => now(),
            'erp_notes' => $notes ?? $this->erp_notes,
        ]);
    }

    /**
     * Get formatted amount due
     * 
     * @return string - "€44.00"
     */
    public function getFormattedAmountAttribute(): string
    {
        $symbol = config('billing.currency.symbol', '€');
        return $symbol . number_format($this->amount_due, 2);
    }

    /**
     * Extract plan from metadata
     * 
     * @return string|null
     */
    public function getPlanAttribute(): ?string
    {
        return $this->metadata['plan'] ?? null;
    }

    /**
     * Extract addons from metadata
     * 
     * @return array - ['planning', 'ai']
     */
    public function getAddonsAttribute(): array
    {
        if (!isset($this->metadata['addons'])) {
            return [];
        }

        $addons = $this->metadata['addons'];
        
        // Handle comma-separated string
        if (is_string($addons)) {
            return empty($addons) ? [] : explode(',', $addons);
        }

        // Handle array
        return is_array($addons) ? $addons : [];
    }
}
