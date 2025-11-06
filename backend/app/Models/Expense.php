<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Expense extends Model
{
    protected $fillable = [
        'technician_id',
        'project_id',
        'date',
        'amount',
        'category',
        'description',
        'attachment_path',
        'status',
        'rejection_reason'
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2'
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function canBeEdited(): bool
    {
        return in_array($this->status, ['submitted', 'rejected']);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function hasAttachment(): bool
    {
        return !empty($this->attachment_path) && Storage::exists($this->attachment_path);
    }

    public function getAttachmentUrl(): ?string
    {
        return $this->hasAttachment() ? Storage::url($this->attachment_path) : null;
    }

    public function submit(): void
    {
        $this->update(['status' => 'submitted']);
    }

    public function approve(): void
    {
        $this->update([
            'status' => 'approved',
            'rejection_reason' => null
        ]);
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason
        ]);
    }
}
