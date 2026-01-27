<?php

use App\Console\Commands\BootstrapDemoTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        \App\Console\Commands\ExpireTrials::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        // Registrar middleware de permissões
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'can.edit.timesheets' => \App\Http\Middleware\CanEditTimesheets::class,
            'can.edit.expenses' => \App\Http\Middleware\CanEditExpenses::class,
            'can_edit_planning' => \App\Http\Middleware\CanEditPlanning::class,
            'can.manage.project.members' => \App\Http\Middleware\CanManageProjectMembers::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'tenant.domain' => \App\Http\Middleware\InitializeTenancyByDomainWithFallback::class,
            'tenant.initialize' => \App\Http\Middleware\InitializeTenancyBySlug::class, // Custom: lookup by slug from X-Tenant header
            'tenant.context' => \App\Http\Middleware\EnsureTenantContext::class,
            'tenant.set-context' => \App\Http\Middleware\SetTenantContext::class, // Billing: Store tenant in container
            'tenant.auth' => \App\Http\Middleware\EnsureAuthenticatedTenant::class,
            'tenant.ensure-domain' => \App\Http\Middleware\EnsureTenantDomainRegistered::class,
            'tenant.prevent-central-domains' => \App\Http\Middleware\AllowCentralDomainFallback::class,
            'sanctum.tenant' => \App\Http\Middleware\SetSanctumTenantConnection::class,
            'auth.token' => \App\Http\Middleware\AuthenticateViaToken::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class, // Billing: Feature-based module access control
            'subscription.write' => \App\Http\Middleware\EnsureSubscriptionWriteAccess::class, // Billing: Read-only mode hardening
            'telemetry.internal' => \App\Http\Middleware\TelemetryInternalMiddleware::class, // Telemetry API authentication
            'telemetry.superadmin' => \App\Http\Middleware\EnsureSuperAdminAccess::class, // SuperAdmin telemetry access control
            'tenant.bootstrapped' => \App\Http\Middleware\EnsureTenantBootstrapped::class, // Ensure tenant permissions exist before policies run
            'user.last_seen' => \App\Http\Middleware\UpdateLastSeenAt::class, // Update tenant user last_seen_at for online tracking
        ]);
        
        // CRITICAL: Prepend SetSanctumTenantConnection to API middleware group
        // This MUST run BEFORE auth:sanctum to configure tenant DB connection
        $middleware->prependToGroup('api', \App\Http\Middleware\SetSanctumTenantConnection::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (!$request->expectsJson()) {
                return null;
            }

            return response()->json([
                'success' => false,
                'code' => 'AUTH_DENIED',
                'message' => $e->getMessage(),
            ], 403);
        });
    })->create();
