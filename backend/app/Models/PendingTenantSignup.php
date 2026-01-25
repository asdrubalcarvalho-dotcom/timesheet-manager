<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PendingTenantSignup extends Model
{
    /**
     * Central model: always use the central connection.
     * This avoids accidental tenant-DB queries if tenancy middleware initializes from request headers.
     */
    protected $connection = 'mysql';

    protected $fillable = [
        'company_name',
        'slug',
        'admin_name',
        'admin_email',
        'password_hash',
        'verification_token',
        'industry',
        'country',
        'timezone',
        'settings',
        'legal_accepted_at',
        'expires_at',
        'verified',
        'email_verified_at',
        'completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'legal_accepted_at' => 'datetime',
        'verified' => 'boolean',
        'email_verified_at' => 'datetime',
        'completed_at' => 'datetime',
        'settings' => 'array',
    ];

    /**
     * Generate a unique verification token.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Check if the signup request has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Check if the signup is still valid (not expired and not verified).
     */
    public function isValid(): bool
    {
        return !$this->verified && !$this->isExpired();
    }

    public function markEmailVerified(?Carbon $at = null): void
    {
        $at ??= Carbon::now();

        // Keep legacy boolean in sync for backwards compatibility.
        $this->forceFill([
            'verified' => true,
            'email_verified_at' => $this->email_verified_at ?? $at,
        ])->save();
    }
}
