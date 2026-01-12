<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialAccount extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
        'provider_email',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Find social account by provider and provider user ID.
     */
    public static function findByProvider(string $provider, string $providerUserId): ?self
    {
        return self::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
    }

    /**
     * Check if a user already has a linked account for the given provider.
     */
    public static function existsForUserAndProvider(int $userId, string $provider): bool
    {
        return self::where('user_id', $userId)
            ->where('provider', $provider)
            ->exists();
    }
}
