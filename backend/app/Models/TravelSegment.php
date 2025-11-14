<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class TravelSegment extends Model
{
    use BelongsToTenant;
    use HasAuditFields;

    protected $fillable = [
        'technician_id',
        'project_id',
        'travel_date',
        'start_at',
        'end_at',
        'duration_minutes',
        'origin_country',
        'origin_location_id',
        'destination_country',
        'destination_location_id',
        'direction',
        'classification_reason',
        'status',
        'linked_timesheet_entry_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    protected static function booted()
    {
        static::saving(function (TravelSegment $segment) {
            // Auto-set travel_date from start_at
            if ($segment->start_at) {
                $segment->travel_date = $segment->start_at->toDateString();
            }

            // Auto-calculate duration from start_at and end_at
            if ($segment->start_at && $segment->end_at) {
                $segment->duration_minutes = $segment->start_at->diffInMinutes($segment->end_at, false);
            }
        });
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function originLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'destination_location_id');
    }

    public function linkedTimesheetEntry(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class, 'linked_timesheet_entry_id');
    }

    /**
     * Classify travel direction based on origin, destination, and contract country
     */
    public static function classifyDirection(
        string $originCountry,
        string $destinationCountry,
        string $contractCountry
    ): array {
        $direction = 'other';
        $reason = '';

        if ($originCountry === $contractCountry && $destinationCountry !== $contractCountry) {
            $direction = 'departure';
            $reason = "Leaving contract country ({$contractCountry}) to {$destinationCountry}";
        } elseif ($destinationCountry === $contractCountry && $originCountry !== $contractCountry) {
            $direction = 'arrival';
            $reason = "Returning to contract country ({$contractCountry}) from {$originCountry}";
        } elseif ($originCountry !== $contractCountry && $destinationCountry !== $contractCountry
            && $originCountry !== $destinationCountry) {
            $direction = 'project_to_project';
            $reason = "Travel between project countries ({$originCountry} → {$destinationCountry})";
        } elseif ($originCountry === $contractCountry && $destinationCountry === $contractCountry) {
            $direction = 'internal';
            $reason = "Internal travel within contract country ({$contractCountry})";
        } else {
            $reason = "Other travel scenario ({$originCountry} → {$destinationCountry})";
        }

        return [
            'direction' => $direction,
            'reason' => $reason,
        ];
    }
}
