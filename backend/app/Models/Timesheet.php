<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Timesheet extends Model
{
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
        'status',
        'rejection_reason'
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'check_out_time' => 'datetime:H:i',
        'hours_worked' => 'decimal:2',
        'lunch_break' => 'integer'
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
        // Only submitted and rejected can be edited
        // approved and closed cannot be edited directly
        return in_array($this->status, ['submitted', 'rejected']);
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
        return $this->status === 'approved';
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
