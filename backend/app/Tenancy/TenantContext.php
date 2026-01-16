<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final readonly class TenantContext
{
    public function __construct(
        public string $locale,
        public string $timezone,
        /**
         * String enum: 'sunday' | 'monday'.
         */
        public string $weekStart,
        public string $currency,
        /**
         * Locale used for number/currency formatting.
         */
        public string $numberLocale,
        /**
         * Date format pattern (display-focused).
         */
        public string $dateFormat,
        /**
         * Time format pattern (display-focused).
         */
        public string $timeFormat,
    ) {
    }

    public static function fromTenant(Tenant $tenant): self
    {
        $locale = app(TenantLocaleResolver::class)->resolve($tenant);
        $numberLocale = (string) data_get($tenant->settings ?? [], 'number_locale', $locale);

        $currency = (string) data_get($tenant->settings ?? [], 'currency', $locale === 'en_US' ? 'USD' : 'EUR');
        $timezone = app(TenantTimezoneResolver::class)->resolve($tenant, $locale, $currency);

        $weekStart = app(TenantWeekConfig::class)->weekStartsOn($tenant, $locale);

        $dateFormat = (string) data_get(
            $tenant->settings ?? [],
            'date_format',
            $locale === 'en_US' ? 'm/d/Y' : 'd/m/Y'
        );

        $timeFormat = (string) data_get(
            $tenant->settings ?? [],
            'time_format',
            $locale === 'en_US' ? 'g:i A' : 'H:i'
        );

        return new self(
            locale: $locale,
            timezone: $timezone,
            weekStart: $weekStart,
            currency: $currency,
            numberLocale: $numberLocale,
            dateFormat: $dateFormat,
            timeFormat: $timeFormat,
        );
    }
}
