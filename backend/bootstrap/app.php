<?php

use App\Console\Commands\BootstrapDemoTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        App\Providers\TenancyServiceProvider::class,
    ])
    ->withCommands([
        BootstrapDemoTenant::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Registrar middleware de permissões
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'can.edit.timesheets' => \App\Http\Middleware\CanEditTimesheets::class,
            'can.edit.expenses' => \App\Http\Middleware\CanEditExpenses::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'tenant.domain' => \App\Http\Middleware\InitializeTenancyByDomainWithFallback::class,
            'tenant.initialize' => \App\Http\Middleware\InitializeTenancyBySlug::class, // Custom: lookup by slug from X-Tenant header
            'tenant.context' => \App\Http\Middleware\EnsureTenantContext::class,
            'tenant.auth' => \App\Http\Middleware\EnsureAuthenticatedTenant::class,
            'tenant.ensure-domain' => \App\Http\Middleware\EnsureTenantDomainRegistered::class,
            'tenant.prevent-central-domains' => \App\Http\Middleware\AllowCentralDomainFallback::class,
            'sanctum.tenant' => \App\Http\Middleware\SetSanctumTenantConnection::class,
            'auth.token' => \App\Http\Middleware\AuthenticateViaToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
