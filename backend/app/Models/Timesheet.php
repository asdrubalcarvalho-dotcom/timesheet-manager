<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\HasAuditFields;

class Timesheet extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'technician_id',
        'project_id',
        'task_id',
        'location_id',
        'date',
        'start_time',
        'end_time',
        'lunch_break',
        'hour_type',
        'hours_worked',
        'description',
        'check_out_time',
        'machine_status',
        'job_status',
        'ai_flagged',
        'ai_score',
        'ai_feedback',
        'status',
        'rejection_reason',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'date' => 'date',
        // TIME fields should not be cast to datetime - they are simple time strings
        'hours_worked' => 'decimal:2',
        'lunch_break' => 'integer',
        'ai_flagged' => 'boolean',
        'ai_score' => 'decimal:2',
        'ai_feedback' => 'array'
    ];

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function canBeEdited(): bool
    {
        // Draft, submitted and rejected can be edited
        // approved and closed cannot be edited directly
        return in_array($this->status, ['draft', 'submitted', 'rejected']);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'submitted';
    }

    public function canBeRejected(): bool
    {
        return in_array($this->status, ['submitted', 'approved']);
    }

    public function canBeClosed(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeReopened(): bool
    {
        return $this->status === 'closed';
    }

    public function canBeSubmitted(): bool
    {
        return in_array($this->status, ['draft', 'rejected']);
    }

    public function submit(): void
    {
        $this->update([
            'status' => 'submitted',
            'rejection_reason' => null
        ]);
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

    public function close(): void
    {
        // Close timesheet (payroll processed)
        $this->update(['status' => 'closed']);
    }

    public function reopen(): void
    {
        // Supervisor can reopen approved timesheet to allow edits
        $this->update([
            'status' => 'submitted',
            'rejection_reason' => null
        ]);
    }
}
