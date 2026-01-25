<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class UpdateLastSeenAt
{
    /**
     * Update cadence to reduce write amplification.
     */
    private const MIN_UPDATE_INTERVAL_SECONDS = 60; // 60s debounce

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        try {
            $user = $request->user();
            if (!$user) {
                return $response;
            }

            // Only in tenant context (tenant DB has the users table).
            if (!tenancy()->initialized) {
                return $response;
            }

            if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'last_seen_at')) {
                return $response;
            }

            $now = now();
            $current = $user->last_seen_at ?? null;

            if ($current !== null) {
                try {
                    $currentAt = $current instanceof Carbon ? $current : Carbon::parse((string) $current);
                    if ($currentAt->diffInSeconds($now) < self::MIN_UPDATE_INTERVAL_SECONDS) {
                        return $response;
                    }
                } catch (\Throwable $e) {
                    // If parsing fails, fall through and update.
                }
            }

            $user->forceFill(['last_seen_at' => $now])->saveQuietly();
        } catch (\Throwable $e) {
            // Telemetry/usage should never break product requests.
        }

        return $response;
    }
}
