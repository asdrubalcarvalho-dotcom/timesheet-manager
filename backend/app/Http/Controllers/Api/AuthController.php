<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\Tenant;
use App\Services\Abuse\Captcha\CaptchaGate;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'tenant_slug' => 'required|string',
            'email' => 'required|email',
            'password' => 'required'
        ]);

        // Find tenant by slug
        $tenant = Tenant::where('slug', $request->tenant_slug)->first();

        if (!$tenant) {
            throw ValidationException::withMessages([
                'tenant_slug' => ['The specified workspace does not exist.'],
            ]);
        }

        // SSO-3: SSO-only enforcement (opt-in per tenant, central feature flag)
        // IMPORTANT: Must happen before entering tenant DB, validating credentials, or issuing tokens.
        if ((bool) $tenant->require_sso) {
            Log::warning('auth.password_blocked_sso_only', [
                'event' => 'auth.password_blocked_sso_only',
                'tenant' => $tenant->slug,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => 'This workspace requires Single Sign-On authentication.',
            ], 403);
        }

        // Anti-Abuse v2: adaptive CAPTCHA on password login.
        // IMPORTANT: If CAPTCHA is required and missing/invalid, do NOT count as a failed login attempt.
        $captchaGate = app(CaptchaGate::class);
        $captchaGate->assertCaptchaIfRequired(
            $request,
            'login',
            (string) $request->email,
            (string) $tenant->slug,
            'login_failures'
        );

        // Initialize tenant context and execute login within tenant database
        return $tenant->run(function () use ($request, $tenant) {
            // MANUAL DATABASE CONNECTION (DatabaseTenancyBootstrapper disabled)
            // Use tenant internal db_name when available; fallback to prefix+tenant id.
            $databaseName = (string) ($tenant->getInternal('db_name') ?? '');

            if ($databaseName === '') {
                $databaseName = (string) config('tenancy.database.prefix', 'timesheet_')
                    . (string) $tenant->id
                    . (string) config('tenancy.database.suffix', '');
            }
            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                $captchaGate = app(CaptchaGate::class);
                $captchaGate->incrementLoginFailure($request, (string) $request->email, (string) $tenant->slug);

                throw ValidationException::withMessages([
                    'email' => ['The provided credentials are incorrect.'],
                ]);
            }

            $captchaGate = app(CaptchaGate::class);
            $captchaGate->clearLoginFailures($request, (string) $request->email, (string) $tenant->slug);

            $token = $user->createToken("tenant-{$tenant->id}", ["tenant:{$tenant->id}"])->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => $this->formatUserResponse($user),
            ]);
        });
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request)
    {
        return response()->json($this->formatUserResponse($request->user()));
    }

    protected function formatUserResponse(User $user): array
    {
        $projectMemberships = $user->memberRecords()
            ->select('project_id', 'project_role', 'expense_role', 'finance_role')
            ->get()
            ->map(function ($membership) {
                return [
                    'project_id' => $membership->project_id,
                    'project_role' => $membership->project_role,
                    'expense_role' => $membership->expense_role,
                    'finance_role' => $membership->finance_role,
                ];
            });

        $tenant = tenant();

        $tenantContext = null;
        if ($tenant) {
            $context = app()->bound(TenantContext::class)
                ? app(TenantContext::class)
                : TenantContext::fromTenant($tenant);

            $tenantContext = $context->toTenantContextArray();
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role ?? 'Technician',
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_owner' => $user->hasRole('Owner'),
            'is_manager' => $user->isProjectManager(),
            'is_technician' => $user->hasRole('Technician'),
            'is_admin' => $user->hasRole('Admin') || $user->hasRole('Owner'),
            'managed_projects' => $user->isProjectManager()
                ? $user->getManagedProjectIds()
                : [],
            'project_memberships' => $projectMemberships,
            'tenant_context' => $tenantContext,
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'region' => data_get($tenant->settings ?? [], 'region'),
                'week_start' => data_get($tenant->settings ?? [], 'week_start'),
            ] : null,
        ];
    }
}
