<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
// SocialAccount stored in tenant DB; BelongsToTenant not required

class SocialAccount extends Model
{
    // BelongsToTenant removed

    protected $fillable = [
        'tenant_id',
        'user_id',
        'provider_name',
        'provider_id',
        'provider_email',
        'provider_username',
        'avatar',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'token_expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
