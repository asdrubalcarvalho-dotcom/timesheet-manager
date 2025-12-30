<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EnsureSubscriptionWriteAccess
{
    /**
     * Allow these write endpoints even in read-only mode.
     * Paths are matched against Request::path() (no leading slash).
     */
    private const WRITE_ALLOWLIST_PREFIXES = [
        'api/billing',
        'api/login',
        'api/register',
        'api/logout',
        'api/user',
        'api/features',
    ];

    public function handle(Request $request, Closure $next)
    {
        // Never block safe methods
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $path = ltrim($request->path(), '/');
        foreach (self::WRITE_ALLOWLIST_PREFIXES as $prefix) {
            if (Str::startsWith($path, $prefix)) {
                return $next($request);
            }
        }

        $tenant = tenancy()->tenant;
        if (!$tenant) {
            return $next($request);
        }

        // Use persisted state first, but fall back to lightweight runtime checks
        $state = $tenant->subscription_state ?: 'active';

        $subscription = $tenant->subscription;
        if ($subscription) {
            if ($subscription->is_trial) {
                if ($subscription->trial_ends_at && $subscription->trial_ends_at->isFuture()) {
                    $state = 'trial';
                } else {
                    $state = 'expired';
                }
            }
            if ($subscription->billing_period_ends_at && $subscription->billing_period_ends_at->isPast()) {
                $state = 'expired';
            }
            if (in_array($subscription->status, ['past_due', 'unpaid'], true)) {
                $state = 'past_due';
            }
            if (in_array($subscription->status, ['canceled', 'cancelled'], true)) {
                $state = 'cancelled';
            }

            // If the subscription is active and not a trial, treat it as active when:
            // - billing_period_ends_at is null (e.g. just upgraded), or
            // - billing_period_ends_at is in the future, or
            // - next_renewal_at is in the future (guards against stale billing_period_ends_at after upgrades)
            if ($subscription->status === 'active' && !$subscription->is_trial) {
                $periodOk = !$subscription->billing_period_ends_at || $subscription->billing_period_ends_at->isFuture();
                $renewalOk = $subscription->next_renewal_at && $subscription->next_renewal_at->isFuture();
                if ($periodOk || $renewalOk) {
                    $state = 'active';
                }
            }
        }

        if (in_array($state, ['active', 'trial'], true)) {
            return $next($request);
        }

        return response()->json([
            'upgrade_required' => true,
            'reason' => 'subscription_expired',
            'read_only' => true,
            'subscription_state' => $state,
            'message' => 'Your subscription has expired. You are in read-only mode.',
        ], 403);
    }
}
