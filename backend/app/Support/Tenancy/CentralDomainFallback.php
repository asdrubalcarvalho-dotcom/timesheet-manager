<?php

namespace App\Support\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

class CentralDomainFallback
{
    public static function shouldAllow(Request $request): bool
    {
        return static::isEnabled()
            && static::environmentAllowed()
            && static::isCentralDomain($request)
            && static::hasTenantHint($request);
    }

    public static function isCentralDomain(Request $request): bool
    {
        $centralDomains = array_map(
            fn ($domain) => Str::lower($domain),
            config('tenancy.central_domains', [])
        );

        return in_array(Str::lower($request->getHost()), $centralDomains, true);
    }

    public static function hasTenantHint(Request $request): bool
    {
        $header = InitializeTenancyByRequestData::$header;
        $parameter = InitializeTenancyByRequestData::$queryParameter;
        
        // Also check our custom slug-based header
        $slugHeader = \App\Http\Middleware\InitializeTenancyBySlug::$header;
        $slugParameter = \App\Http\Middleware\InitializeTenancyBySlug::$queryParameter;

        return $request->headers->has($header) 
            || $request->query->has($parameter)
            || $request->headers->has($slugHeader)
            || ($slugParameter && $request->query->has($slugParameter));
    }

    public static function isEnabled(): bool
    {
        $config = static::config();

        return (bool) ($config['enabled'] ?? false);
    }

    public static function environmentAllowed(): bool
    {
        $config = static::config();
        $environments = array_map('trim', $config['environments'] ?? []);

        return empty($environments) || in_array(app()->environment(), $environments, true);
    }

    protected static function config(): array
    {
        return config('tenancy.domains.central_fallback', [
            'enabled' => false,
            'environments' => [],
        ]);
    }
}
