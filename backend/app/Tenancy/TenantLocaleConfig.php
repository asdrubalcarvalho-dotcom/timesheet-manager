<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final class TenantLocaleConfig
{
    /**
     * Resolves presentation-focused locale configuration from tenant context.
     *
     * IMPORTANT: This is presentation-only. It must not change stored values
     * or business logic. Controllers/components should consume the resolved
     * context instead of branching on region.
     */
    public function resolve(Tenant $tenant): array
    {
        $region = strtoupper(trim((string) data_get($tenant->settings ?? [], 'region', '')));

        $defaults = match ($region) {
            'US' => [
                // Laravel/Carbon/intl-friendly locale.
                'app_locale' => 'en_US',
                // IETF BCP 47 tag for frontend Intl APIs.
                'locale' => 'en-US',
                'number_locale' => 'en_US',
                'currency' => 'USD',
                'currency_symbol' => '$',
                'date_format' => 'm/d/Y',
                'time_format' => 'g:i A',
                'decimal_separator' => '.',
                'thousands_separator' => ',',
            ],
            // Default behavior is EU-style.
            default => [
                'app_locale' => 'pt_PT',
                'locale' => 'pt-PT',
                'number_locale' => 'pt_PT',
                'currency' => 'EUR',
                'currency_symbol' => '€',
                'date_format' => 'd/m/Y',
                'time_format' => 'H:i',
                'decimal_separator' => ',',
                'thousands_separator' => '.',
            ],
        };

        // Allow explicit tenant overrides, while keeping region-driven defaults.
        $resolvedAppLocale = app(TenantLocaleResolver::class)->resolve($tenant);
        if (trim((string) data_get($tenant->settings ?? [], 'locale', '')) !== '') {
            $defaults['app_locale'] = $resolvedAppLocale;
            // Derive IETF tag from app locale when explicitly set.
            $defaults['locale'] = str_replace('_', '-', $resolvedAppLocale);
        }

        $defaults['number_locale'] = (string) data_get(
            $tenant->settings ?? [],
            'number_locale',
            $defaults['number_locale']
        );

        $defaults['currency'] = (string) data_get(
            $tenant->settings ?? [],
            'currency',
            $defaults['currency']
        );

        $defaults['currency_symbol'] = (string) data_get(
            $tenant->settings ?? [],
            'currency_symbol',
            $defaults['currency_symbol']
        );

        $defaults['date_format'] = (string) data_get(
            $tenant->settings ?? [],
            'date_format',
            $defaults['date_format']
        );

        $defaults['time_format'] = (string) data_get(
            $tenant->settings ?? [],
            'time_format',
            $defaults['time_format']
        );

        // Normalize separators if number locale implies a known culture.
        // (Still fully contained inside this config class.)
        $numberLocale = (string) $defaults['number_locale'];
        if (stripos($numberLocale, 'en') === 0) {
            $defaults['decimal_separator'] = '.';
            $defaults['thousands_separator'] = ',';
        } elseif (stripos($numberLocale, 'pt') === 0) {
            $defaults['decimal_separator'] = ',';
            $defaults['thousands_separator'] = '.';
        }

        return $defaults;
    }
}
