<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final class TenantWeekConfig
{
    public const SUNDAY = 'sunday';
    public const MONDAY = 'monday';

    /**
     * Returns 'sunday' or 'monday'.
     *
     * IMPORTANT: Do not use Carbon::startOfWeek() directly in domain code.
     */
    public function weekStartsOn(Tenant $tenant, string $locale): string
    {
        $raw = (string) data_get($tenant->settings ?? [], 'week_start', '');
        $raw = strtolower(trim($raw));

        if (in_array($raw, [self::SUNDAY, self::MONDAY], true)) {
            return $raw;
        }

        // Default behavior is EU-style unless tenant settings indicate a US locale.
        return $locale === 'en_US' ? self::SUNDAY : self::MONDAY;
    }

    /**
     * 0 = Sunday, 1 = Monday (compatible with many calendar libraries).
     */
    public function weekStartsOnIndex(Tenant $tenant, string $locale): int
    {
        return $this->weekStartsOn($tenant, $locale) === self::SUNDAY ? 0 : 1;
    }
}
