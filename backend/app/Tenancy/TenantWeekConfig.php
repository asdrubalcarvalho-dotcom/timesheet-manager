<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final class TenantWeekConfig
{
    public const SUNDAY = 'sunday';
    public const MONDAY = 'monday';
    public const TUESDAY = 'tuesday';
    public const WEDNESDAY = 'wednesday';
    public const THURSDAY = 'thursday';
    public const FRIDAY = 'friday';
    public const SATURDAY = 'saturday';

    private const DAYS = [
        self::SUNDAY,
        self::MONDAY,
        self::TUESDAY,
        self::WEDNESDAY,
        self::THURSDAY,
        self::FRIDAY,
        self::SATURDAY,
    ];

    /**
     * Returns 'sunday' or 'monday'.
     *
     * IMPORTANT: Do not use Carbon::startOfWeek() directly in domain code.
     */
    public function weekStartsOn(Tenant $tenant, string $locale): string
    {
        $raw = (string) data_get($tenant->settings ?? [], 'week_start', '');
        $raw = strtolower(trim($raw));

        if (in_array($raw, self::DAYS, true)) {
            return $raw;
        }

        // Default behavior is EU-style unless tenant settings indicate a US locale.
        return $locale === 'en_US' ? self::SUNDAY : self::MONDAY;
    }

    /**
     * Returns the configured end-of-week day name.
     *
     * Defaults:
     * - US (Sunday start): Saturday end
     * - EU (Monday start): Sunday end
     */
    public function weekEndsOn(Tenant $tenant, string $locale): string
    {
        $raw = (string) data_get($tenant->settings ?? [], 'week_end', '');
        $raw = strtolower(trim($raw));

        if (in_array($raw, self::DAYS, true)) {
            $start = $this->weekStartsOn($tenant, $locale);
            if ($raw !== $start) {
                return $raw;
            }
        }

        return $this->derivedWeekEnd($tenant, $locale);
    }

    private function derivedWeekEnd(Tenant $tenant, string $locale): string
    {
        $startIndex = $this->weekStartsOnIndex($tenant, $locale);
        $endIndex = ($startIndex + 6) % 7;

        return self::DAYS[$endIndex];
    }

    /**
     * 0 = Sunday, 1 = Monday (compatible with many calendar libraries).
     */
    public function weekStartsOnIndex(Tenant $tenant, string $locale): int
    {
        return array_search($this->weekStartsOn($tenant, $locale), self::DAYS, true) ?: 0;
    }

    public function weekEndsOnIndex(Tenant $tenant, string $locale): int
    {
        $idx = array_search($this->weekEndsOn($tenant, $locale), self::DAYS, true);
        return $idx === false ? 6 : $idx;
    }
}
