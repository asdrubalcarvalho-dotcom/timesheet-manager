<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\HasAuditFields;

class Task extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'task_type',
        'is_active',
        'estimated_hours',
        'start_date',
        'end_date',
        'progress',
        'dependencies',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'estimated_hours' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'progress' => 'integer',
        'dependencies' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function getTotalHours(): float
    {
        return $this->timesheets()->where('status', 'approved')->sum('hours_worked');
    }

    public function resources(): BelongsToMany
    {
        return $this->belongsToMany(Resource::class, 'resource_task')->withPivot('allocation');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_task');
    }
}
