<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final readonly class TenantContext
{
    public function __construct(
        /**
         * Region from tenant settings (e.g. EU/US). Used for presentation defaults.
         */
        public ?string $region,
        /**
         * IETF locale tag for frontend Intl APIs (e.g. en-US, pt-PT).
         */
        public string $presentationLocale,
        public string $locale,
        public string $timezone,
        /**
         * String enum: 'sunday' | 'monday'.
         */
        public string $weekStart,
        public string $currency,
        public string $currencySymbol,
        /**
         * Locale used for number/currency formatting.
         */
        public string $numberLocale,
        public string $decimalSeparator,
        public string $thousandsSeparator,
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
        $localeConfig = app(TenantLocaleConfig::class)->resolve($tenant);

        $region = strtoupper(trim((string) data_get($tenant->settings ?? [], 'region', '')));
        $region = $region !== '' ? $region : null;

        $locale = (string) $localeConfig['app_locale'];
        $presentationLocale = (string) $localeConfig['locale'];
        $numberLocale = (string) $localeConfig['number_locale'];

        $currency = (string) $localeConfig['currency'];
        $currencySymbol = (string) $localeConfig['currency_symbol'];

        $timezone = app(TenantTimezoneResolver::class)->resolve($tenant, $locale, $currency);
        $weekStart = app(TenantWeekConfig::class)->weekStartsOn($tenant, $locale);

        $dateFormat = (string) $localeConfig['date_format'];
        $timeFormat = (string) $localeConfig['time_format'];

        $decimalSeparator = (string) $localeConfig['decimal_separator'];
        $thousandsSeparator = (string) $localeConfig['thousands_separator'];

        return new self(
            region: $region,
            presentationLocale: $presentationLocale,
            locale: $locale,
            timezone: $timezone,
            weekStart: $weekStart,
            currency: $currency,
            currencySymbol: $currencySymbol,
            numberLocale: $numberLocale,
            decimalSeparator: $decimalSeparator,
            thousandsSeparator: $thousandsSeparator,
            dateFormat: $dateFormat,
            timeFormat: $timeFormat,
        );
    }

    /**
     * Presentation-only, stable API surface for tenant-driven locale behavior.
     */
    public function toTenantContextArray(): array
    {
        return [
            'region' => $this->region,
            'timezone' => $this->timezone,
            'locale' => $this->presentationLocale,
            'date_format' => $this->dateFormat,
            'currency' => $this->currency,
            'currency_symbol' => $this->currencySymbol,
        ];
    }
}
