<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use App\Traits\HasAuditFields;

class Expense extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'technician_id',
        'project_id',
        'date',
        'amount',
        'category',
        'description',
        'attachment_path',
        'status',
        'rejection_reason',
        'expense_type',
        'distance_km',
        'rate_per_km',
        'vehicle_type',
        'finance_approved_by',
        'finance_approved_at',
        'finance_notes',
        'paid_at',
        'payment_reference',
        'card_transaction_id',
        'transaction_date',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'rate_per_km' => 'decimal:2',
        'finance_approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'transaction_date' => 'datetime',
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_approved_by');
    }

    // Status Check Methods
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    public function canBeSubmitted(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    public function canBeApprovedByManager(): bool
    {
        return $this->status === 'submitted';
    }

    public function canBeReviewedByFinance(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeApprovedByFinance(): bool
    {
        return $this->status === 'finance_review';
    }

    public function canBeMarkedAsPaid(): bool
    {
        return $this->status === 'finance_approved';
    }

    // Type Check Methods
    public function isReimbursement(): bool
    {
        return $this->expense_type === 'reimbursement';
    }

    public function isMileage(): bool
    {
        return $this->expense_type === 'mileage';
    }

    public function isCompanyCard(): bool
    {
        return $this->expense_type === 'company_card';
    }

    // Calculated Amount for Mileage
    public function getCalculatedAmount(): float
    {
        if ($this->isMileage() && $this->distance_km && $this->rate_per_km) {
            return round($this->distance_km * $this->rate_per_km, 2);
        }
        return $this->amount ?? 0;
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'finance_review', 'finance_approved', 'paid']);
    }

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path) && Storage::exists($this->attachment_path);
    }

    public function getAttachmentUrl(): ?string
    {
        return $this->hasAttachment() ? Storage::url($this->attachment_path) : null;
    }

    // Workflow Methods
    public function submit(): void
    {
        if (!$this->canBeSubmitted()) {
            throw new \Exception('Expense cannot be submitted in current status');
        }
        
        $this->update([
            'status' => 'submitted',
            'rejection_reason' => null
        ]);
    }

    public function approveByManager(): void
    {
        if (!$this->canBeApprovedByManager()) {
            throw new \Exception('Expense cannot be approved by manager in current status');
        }
        
        // When manager approves, goes to finance_review
        $this->update([
            'status' => 'finance_review',
            'rejection_reason' => null
        ]);
    }

    public function approveByFinance(int $userId, ?string $notes = null, ?string $paymentReference = null): void
    {
        if (!$this->canBeApprovedByFinance()) {
            throw new \Exception('Expense cannot be approved by finance in current status');
        }
        
        $this->update([
            'status' => 'finance_approved',
            'finance_approved_by' => $userId,
            'finance_approved_at' => now(),
            'finance_notes' => $notes,
            'payment_reference' => $paymentReference,
        ]);
    }

    public function markAsPaid(?string $paymentReference = null): void
    {
        if (!$this->canBeMarkedAsPaid()) {
            throw new \Exception('Expense cannot be marked as paid in current status');
        }
        
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_reference' => $paymentReference ?? $this->payment_reference,
        ]);
    }

    public function reject(string $reason): void
    {
        // Can be rejected by manager (from submitted) or finance (from finance_review)
        if (!in_array($this->status, ['submitted', 'finance_review'])) {
            throw new \Exception('Expense cannot be rejected in current status');
        }
        
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason
        ]);
    }

    // Legacy methods for backwards compatibility
    public function approve(): void
    {
        // Deprecated: use approveByManager() or approveByFinance() instead
        $this->approveByManager();
    }
}
