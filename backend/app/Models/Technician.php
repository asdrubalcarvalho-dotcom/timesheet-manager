<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Technician extends Model
{
    use HasAuditFields, HasFactory;

    protected $fillable = [
        'name',
        'email',
        'role',
        'hourly_rate',
        'is_active',
        'user_id',
        'worker_id',
        'worker_name',
        'worker_contract_country',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'hourly_rate' => 'decimal:2',
        'is_active' => 'boolean'
    ];

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }
}
