<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnsureTenantDomainRegistered
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('tenancy.domains.auto_register_on_request', false)) {
            return $next($request);
        }

        $baseDomain = Str::lower((string) config('tenancy.domains.base'));

        if ($baseDomain === '') {
            return $next($request);
        }

        $host = Str::lower($request->getHost());

        if ($host === $baseDomain || ! Str::endsWith($host, '.' . $baseDomain)) {
            return $next($request);
        }

        $domainModelClass = config('tenancy.domain_model');
        /** @var \Stancl\Tenancy\Database\Models\Domain $domainModel */
        $domainModel = app($domainModelClass);

        if ($domainModel->newQuery()->where('domain', $host)->exists()) {
            return $next($request);
        }

        $slug = Str::beforeLast($host, '.' . $baseDomain);

        if ($slug === '') {
            return $next($request);
        }

        $tenant = Tenant::where('slug', $slug)->first();

        if (! $tenant) {
            Log::warning('Auto domain registration skipped — tenant slug not found.', [
                'slug' => $slug,
                'host' => $host,
            ]);

            return $next($request);
        }

        $domainModel->newQuery()->create([
            'domain' => $host,
            'tenant_id' => $tenant->id,
        ]);

        Log::info('Auto domain registration created.', [
            'tenant' => $tenant->id,
            'domain' => $host,
        ]);

        return $next($request);
    }
}
