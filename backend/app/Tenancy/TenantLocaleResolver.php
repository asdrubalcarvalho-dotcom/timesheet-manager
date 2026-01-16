<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;

final class TenantLocaleResolver
{
    public function resolve(?Tenant $tenant): string
    {
        $raw = $tenant ? (string) data_get($tenant->settings ?? [], 'locale', '') : '';

        $locale = trim($raw) !== ''
            ? trim($raw)
            : (string) config('app.locale', 'en');

        // Normalize common variants (e.g. en-US -> en_US)
        $locale = str_replace('-', '_', $locale);

        return $locale;
    }
}
