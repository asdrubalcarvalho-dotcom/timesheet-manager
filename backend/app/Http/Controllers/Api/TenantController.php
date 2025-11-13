<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Domain;

class TenantController extends Controller
{
    /**
     * Register a new tenant (company) with initial admin user.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            'slug' => 'required|string|max:100|unique:tenants,slug|regex:/^[a-z0-9-]+$/',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|string|email|max:255',
            'admin_password' => 'required|string|min:8|confirmed',
            'industry' => 'nullable|string|max:100',
            'country' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:50',
            'plan' => 'nullable|in:trial,standard,premium,enterprise',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Prevent reserved slugs
        $reservedSlugs = ['admin', 'api', 'system', 'app', 'www', 'mail', 'ftp', 'localhost', 'central'];
        if (in_array(strtolower($request->slug), $reservedSlugs)) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['slug' => ['This slug is reserved and cannot be used.']]
            ], 422);
        }

        try {
            // Define base domain for tenant URLs
            $baseDomain = config('app.domain', 'localhost:3000');
            
            // 1. Create Tenant in central database (triggers TenantCreated event)
            // TenantCreated event automatically creates Domain via ProvisionTenantDomain listener
            $tenant = Tenant::create([
                'name' => $request->company_name,
                'slug' => $request->slug,
                'owner_email' => $request->admin_email,
                'status' => 'active',
                'plan' => $request->plan ?? 'trial',
                'timezone' => $request->timezone ?? 'UTC',
                'trial_ends_at' => now()->addDays(14),
            ]);

            // 2. Create Company record (in central DB)
            Company::create([
                'tenant_id' => $tenant->id,
                'name' => $request->company_name,
                'industry' => $request->industry,
                'country' => $request->country,
                'timezone' => $request->timezone ?? 'UTC',
                'status' => 'active',
            ]);

            // 3. Create tenant database manually (since we disabled automatic jobs)
            $databaseName = $tenant->tenancy_db_name ?? "timesheet_{$tenant->id}";
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // 5. Initialize tenant context and seed database
            $adminToken = null;
            $tenant->run(function () use ($request, $tenant, &$adminToken) {
                // Run migrations if not already run by TenantCreated event
                \Artisan::call('migrate', [
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                // Seed roles and permissions first
                \Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                    '--force' => true,
                ]);

                // Create owner user in tenant database (no tenant_id in multi-DB tenancy)
                $owner = User::create([
                    'name' => $request->admin_name,
                    'email' => $request->admin_email,
                    'password' => Hash::make($request->admin_password),
                    'email_verified_at' => now(),
                ]);

                // Assign Owner role (highest privilege level)
                $owner->assignRole('Owner');

                // Create Technician record for Owner user
                \App\Models\Technician::create([
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'role' => 'owner',
                    'phone' => null,
                    'user_id' => $owner->id,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);

                // Generate API token for immediate use
                $adminToken = $owner->createToken('onboarding-token')->plainTextToken;
            });

            return response()->json([
                'status' => 'ok',
                'message' => 'Tenant created successfully',
                'tenant' => $request->slug,
                'database' => config('tenancy.database.prefix', 'timesheet_') . $tenant->id,
                'tenant_info' => [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                    'domain' => $request->slug . '.' . $baseDomain,
                    'status' => $tenant->status,
                    'trial_ends_at' => $tenant->trial_ends_at->toISOString(),
                ],
                'admin' => [
                    'email' => $request->admin_email,
                    'token' => $adminToken,
                ],
                'next_steps' => [
                    'login_url' => (app()->environment('local') ? 'http://' : 'https://') . $request->slug . '.' . $baseDomain . '/login',
                    'api_header' => 'X-Tenant: ' . $request->slug,
                ]
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Tenant registration failed', [
                'slug' => $request->slug ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Cleanup on failure
            try {
                if (isset($tenant)) {
                    $tenant->delete(); // This will trigger TenantDeleted event and drop database
                }
            } catch (\Exception $cleanupError) {
                \Log::error('Cleanup failed after tenant registration error', [
                    'error' => $cleanupError->getMessage()
                ]);
            }

            return response()->json([
                'message' => 'Tenant registration failed',
                'error' => app()->environment('local') ? $e->getMessage() : 'An error occurred during registration. Please try again.'
            ], 500);
        }
    }

    /**
     * Check if a tenant slug is available.
     */
    public function checkSlug(Request $request): JsonResponse
    {
        $slug = $request->input('slug');

        if (!$slug) {
            return response()->json(['available' => false, 'message' => 'Slug is required'], 400);
        }

        // Check reserved words
        $reservedSlugs = ['admin', 'api', 'system', 'app', 'www', 'mail', 'ftp', 'localhost', 'central'];
        if (in_array(strtolower($slug), $reservedSlugs)) {
            return response()->json([
                'available' => false,
                'message' => 'This slug is reserved and cannot be used.'
            ]);
        }

        // Check if slug already exists
        $exists = Tenant::where('slug', $slug)->exists();

        return response()->json([
            'available' => !$exists,
            'message' => $exists ? 'This slug is already taken.' : 'Slug is available.'
        ]);
    }

    /**
     * List all tenants (Admin only).
     */
    public function index(): JsonResponse
    {
        $tenants = Tenant::with('domains')->get()->map(function ($tenant) {
            return [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
                'owner_email' => $tenant->owner_email,
                'trial_ends_at' => $tenant->trial_ends_at,
                'created_at' => $tenant->created_at,
                'domains' => $tenant->domains->pluck('domain'),
            ];
        });

        return response()->json(['tenants' => $tenants]);
    }

    /**
     * Get single tenant details.
     */
    public function show(string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->with('domains', 'company')->firstOrFail();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'plan' => $tenant->plan,
                'owner_email' => $tenant->owner_email,
                'timezone' => $tenant->timezone,
                'trial_ends_at' => $tenant->trial_ends_at,
                'deactivated_at' => $tenant->deactivated_at,
                'created_at' => $tenant->created_at,
                'domains' => $tenant->domains->pluck('domain'),
                'company' => $tenant->company,
            ]
        ]);
    }
}
