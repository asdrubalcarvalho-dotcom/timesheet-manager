<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// Event model lives in tenant DB; no BelongsToTenant trait required

class Event extends Model
{
    // BelongsToTenant removed

    protected $fillable = [
        'tenant_id',
        'title',
        'start',
        'end',
        'project_id',
        'task_id',
        'location_id',
        'technician_id',
        'type',
    ];

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

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
