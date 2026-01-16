<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final class TenantTimezoneResolver
{
    public function resolve(?Tenant $tenant, string $locale, string $currency): string
    {
        if ($tenant) {
            $fromSettings = (string) data_get($tenant->settings ?? [], 'timezone', '');
            if (trim($fromSettings) !== '') {
                return trim($fromSettings);
            }
        }

        // Safe defaults driven by tenant settings (locale/currency are tenant-derived).
        // US defaults should win even if a legacy column exists with a non-US value.
        if ($locale === 'en_US' || $currency === 'USD') {
            return 'America/New_York';
        }

        if ($tenant && !empty($tenant->timezone)) {
            // Backward-compatible fallback to existing column.
            return (string) $tenant->timezone;
        }

        return (string) config('app.timezone', 'UTC');
    }
}
