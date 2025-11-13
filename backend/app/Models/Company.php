<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
// Company model: keep as-is. BelongsToTenant removed to avoid tenant_id constraint in tenant DB.

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'legal_name',
        'industry',
        'size',
        'timezone',
        'country',
        'state',
        'city',
        'address_line1',
        'address_line2',
        'postal_code',
        'phone',
        'billing_email',
        'support_email',
        'website',
        'vat_number',
        'registration_number',
        'status',
        'settings',
        'metadata',
    ];

    protected $casts = [
        'settings' => 'array',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
