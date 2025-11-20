<?php

namespace Modules\Billing\Models;

use App\Models\Tenant;
use App\Models\User;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TenantFeature Model
 * 
 * Manages feature flags for multi-tenant SaaS application.
 * Controls which modules are enabled for each tenant.
 * 
 * @property int $id
 * @property string $tenant_id
 * @property string $module_name
 * @property bool $is_enabled
 * @property \Carbon\Carbon|null $expires_at
 * @property int|null $max_users
 * @property array|null $metadata
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TenantFeature extends Model
{
    use HasAuditFields;

    protected $fillable = [
        'tenant_id',
        'module_name',
        'is_enabled',
        'expires_at',
        'max_users',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'expires_at' => 'datetime',
        'max_users' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Available modules in the system
     */
    public const MODULES = [
        'timesheets' => 'Timesheets & Tasks',
        'expenses' => 'Expenses Management',
        'travel' => 'Travel Segments',
        'planning' => 'Planning & Gantt',
        'billing' => 'Billing & Subscriptions',
        'reporting' => 'Advanced Reporting',
    ];

    /**
     * Core modules (always enabled)
     */
    public const CORE_MODULES = [
        'timesheets',
        'expenses',
    ];

    /**
     * Get the tenant that owns this feature
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    /**
     * Get the user who created this record
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this record
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Check if feature is currently active (enabled + not expired)
     */
    public function isActive(): bool
    {
        if (!$this->is_enabled) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Check if feature is in trial period
     */
    public function isTrialing(): bool
    {
        return $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Get days remaining in trial
     */
    public function daysRemainingInTrial(): ?int
    {
        if (!$this->isTrialing()) {
            return null;
        }

        return now()->diffInDays($this->expires_at);
    }

    /**
     * Enable the feature
     */
    public function enable(): bool
    {
        $this->is_enabled = true;
        return $this->save();
    }

    /**
     * Disable the feature
     */
    public function disable(): bool
    {
        $this->is_enabled = false;
        return $this->save();
    }

    /**
     * Check if module is a core module (cannot be disabled)
     */
    public function isCoreModule(): bool
    {
        return in_array($this->module_name, self::CORE_MODULES);
    }

    /**
     * Scope: Only active features
     */
    public function scopeActive($query)
    {
        return $query->where('is_enabled', true)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    /**
     * Scope: Only expired features
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: Features for a specific tenant
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
