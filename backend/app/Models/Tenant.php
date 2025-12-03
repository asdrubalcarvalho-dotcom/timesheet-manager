<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Modules\Billing\Models\Subscription;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDataColumn;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasFactory, HasDatabase, HasDataColumn;

    protected $fillable = [
        'id',
        'name',
        'billing_plan',
        'slug',
        'stripe_customer_id',
        'stripe_subscription_id',
        'active_addons',
        'subscription_renews_at',
        'subscription_status',
        'subscription_last_event',
        'subscription_last_status_change_at',
        'is_paused',
        'status',
        'plan',
        'timezone',
        'owner_email',
        'trial_ends_at',
        'deactivated_at',
        'settings',
        'data',
        // Stripe Tax fields
        'billing_country',
        'billing_address',
        'billing_postal_code',
        'billing_vat_number',
    ];

    protected $casts = [
        'settings' => 'array',
        'data' => 'array',
        'active_addons' => 'array',
        'trial_ends_at' => 'datetime',
        'subscription_renews_at' => 'datetime',
        'subscription_last_status_change_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'is_paused' => 'boolean',
        // Stripe Tax fields
        'billing_country' => 'string',
        'billing_address' => 'string',
        'billing_postal_code' => 'string',
        'billing_vat_number' => 'string',
    ];

    /**
     * Internal keys stored inside the `data` JSON column.
     * Note: These will be prefixed with 'tenancy_' automatically.
     */
    public function internalKeys(): array
    {
        return [
            'db_name',
            'db_driver',
            'db_host',
            'db_port',
            'db_username',
            'db_password',
        ];
    }

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            // Auto-generate ULID if not already set
            if (empty($tenant->id)) {
                $tenant->id = (string) Str::ulid();
            }

            // Auto-generate slug from name if not set
            if (empty($tenant->slug) && ! empty($tenant->name)) {
                $tenant->slug = Str::slug($tenant->name);
            }

            // CRITICAL: Set internal DB config BEFORE first save
            // VirtualColumn trait encodes attributes during 'creating' and 'saving' events
            // If we set these in 'created' event, VirtualColumn has already encoded data column
            // and our setInternal() calls won't be persisted to the database
            $tenant->setInternal('db_name', 'timesheet_' . $tenant->id);
            $tenant->setInternal('db_driver', 'mysql');
            $tenant->setInternal('db_host', config('database.connections.tenant.host'));
            $tenant->setInternal('db_port', config('database.connections.tenant.port'));
            $tenant->setInternal('db_username', config('database.connections.tenant.username'));
            $tenant->setInternal('db_password', config('database.connections.tenant.password'));
        });
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function billingProfile(): HasOne
    {
        return $this->hasOne(BillingProfile::class, 'tenant_id');
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
            'billing_plan',
            'status',
            'plan',
            'timezone',
            'owner_email',
            'trial_ends_at',
            'deactivated_at',
            'settings',
        ]);
    }

    /**
     * Billing plan accessor falls back to legacy `plan` column for backward compatibility.
     */
    public function getBillingPlanAttribute(?string $value): string
    {
        if (! empty($value)) {
            return $value;
        }

        return $this->plan ?? 'starter';
    }
}
