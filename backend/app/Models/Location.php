<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Location extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'name',
        'country',
        'city',
        'address',
        'postal_code',
        'timezone',
        'meta',
        'latitude',
        'longitude',
        'asset_id',
        'oem_id',
        'is_active',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'is_active' => 'boolean',
        'meta' => 'array'
    ];

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address,
            $this->city,
            $this->postal_code,
            $this->country
        ]);
        
        return implode(', ', $parts);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude && $this->longitude;
    }

    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'location_task');
    }
}
