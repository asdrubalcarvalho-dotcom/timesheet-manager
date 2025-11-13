<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasFactory, HasDatabase;

    protected $fillable = [
        'id',
        'name',
        'slug',
        'status',
        'plan',
        'timezone',
        'owner_email',
        'trial_ends_at',
        'deactivated_at',
        'settings',
        'data',
    ];

    protected $casts = [
        'settings' => 'array',
        'data' => 'array',
        'trial_ends_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            if (empty($tenant->id)) {
                $tenant->id = (string) Str::ulid();
            }

            if (empty($tenant->slug) && ! empty($tenant->name)) {
                $tenant->slug = Str::slug($tenant->name);
            }
            
            // Set database name for TenantWithDatabase interface
            if (empty($tenant->tenancy_db_name)) {
                $tenant->setInternal('tenancy_db_name', 'timesheet_' . $tenant->id);
            }
        });
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    public static function getCustomColumns(): array
    {
        return array_merge(parent::getCustomColumns(), [
            'slug',
            'name',
            'status',
            'plan',
            'timezone',
            'owner_email',
            'trial_ends_at',
            'deactivated_at',
            'settings',
        ]);
    }
}
