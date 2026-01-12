<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTenantAiRequest;
use App\Models\Company;
use App\Models\PendingTenantSignup;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantEmailVerification;
use App\Services\Abuse\Captcha\CaptchaGate;
use App\Services\Security\EmailPolicyService;
use App\Services\Billing\PlanManager;
use App\Support\EmailRecipient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Modules\Billing\Models\Subscription;
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
            // Central endpoint: target central DB explicitly in case tenancy is initialized from request headers.
            'slug' => 'required|string|max:100|unique:mysql.tenants,slug|regex:/^[a-z0-9-]+$/',
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

        app(EmailPolicyService::class)->assertAllowedEmail(
            (string) $request->admin_email,
            $request,
            ['tenant_slug' => (string) $request->slug]
        );

        $captchaGate = app(CaptchaGate::class);
        $captchaGate->recordRegisterAttempt($request, 'tenants/register', (string) $request->admin_email, (string) $request->slug);
        $captchaGate->assertCaptchaIfRequired($request, 'tenants/register', (string) $request->admin_email, (string) $request->slug, 'register_adaptive');

        try {
            // Base domain for tenant URLs (frontend)
            $baseDomain = config('app.domain', 'localhost:3000');

            // 1. Create Tenant in central database
            // The Tenant model automatically sets tenancy_db_name in booted() method
            try {
                $tenant = Tenant::create([
                    'name'         => $request->company_name,
                    'slug'         => $request->slug,
                    'owner_email'  => $request->admin_email,
                    'status'       => 'active',
                    'plan'         => $request->plan ?? 'trial',
                    'timezone'     => $request->timezone ?? 'UTC',
                    'trial_ends_at'=> now()->addDays(14),
                ]);
                \Log::info('Tenant::create() SUCCESS');
            } catch (\Throwable $e) {
                \Log::error('Tenant::create() FAILED', ['error' => $e->getMessage(), 'line' => $e->getLine(), 'file' => $e->getFile()]);
                throw $e;
            }

            // CRITICAL: Refresh model to decode VirtualColumn data
            // The 'creating' event sets internal keys, but they're only persisted after save()
            // We need to refresh the model so VirtualColumn decodes the JSON data column
            \Log::info('BEFORE refresh', ['attributes' => array_keys($tenant->getAttributes())]);
            $tenant->refresh();
            \Log::info('AFTER refresh', ['attributes' => array_keys($tenant->getAttributes()), 'has_tenancy_db_name' => $tenant->hasAttribute('tenancy_db_name')]);

            // 2. Get database name from tenant data (auto-set by model)
            // Note: getInternal() automatically handles the 'tenancy_' prefix
            $databaseName = $tenant->getInternal('db_name');

            // 3. Create tenant database manually
            DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            // 4. Create Company record (central DB)
            Company::create([
                'tenant_id' => $tenant->id,
                'name'      => $request->company_name,
                'industry'  => $request->industry,
                'country'   => $request->country,
                'timezone'  => $request->timezone ?? 'UTC',
                'status'    => 'active',
            ]);

            // 5. Start 15-day Enterprise trial with all features enabled
            $planManager = app(PlanManager::class);
            $subscription = $planManager->startTrialForTenant($tenant);
            \Log::info('Trial subscription created', [
                'tenant_id' => $tenant->id,
                'plan' => $subscription->plan,
                'is_trial' => $subscription->is_trial,
                'trial_ends_at' => $subscription->trial_ends_at,
            ]);

            // 5b. Create Stripe Customer and BillingProfile (if Stripe enabled)
            if (config('payments.driver') === 'stripe') {
                try {
                    \Stripe\Stripe::setApiKey(config('payments.stripe.secret_key'));
                    
                    $stripeCustomer = \Stripe\Customer::create([
                        'name' => $request->company_name,
                        'email' => $request->admin_email,
                        'metadata' => [
                            'tenant_id' => $tenant->id,
                            'slug' => $tenant->slug,
                        ],
                    ]);

                    \App\Models\BillingProfile::create([
                        'tenant_id' => $tenant->id,
                        'gateway' => 'stripe',
                        'stripe_customer_id' => $stripeCustomer->id,
                        'billing_email' => $request->admin_email,
                        'billing_name' => $request->company_name,
                    ]);

                    \Log::info('Stripe customer created', [
                        'tenant_id' => $tenant->id,
                        'customer_id' => $stripeCustomer->id,
                    ]);
                } catch (\Exception $e) {
                    \Log::warning('Stripe customer creation failed (continuing anyway)', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            //  6. Initialize tenant context and seed database
            $adminToken = null;

            // WORKAROUND: Manually set tenant connection config before $tenant->run()
            // This fixes the "array_merge(): Argument #1 must be of type array, null given" error
            config(['database.connections.tenant' => [
                'driver' => 'mysql',
                'host' => config('database.connections.mysql.host'),
                'port' => config('database.connections.mysql.port'),
                'database' => $databaseName,
                'username' => config('database.connections.mysql.username'),
                'password' => config('database.connections.mysql.password'),
                'unix_socket' => config('database.connections.mysql.unix_socket'),
                'charset' => config('database.connections.mysql.charset'),
                'collation' => config('database.connections.mysql.collation'),
                'prefix' => '',
                'prefix_indexes' => true,
                'strict' => true,
                'engine' => null,
                'options' => config('database.connections.mysql.options', []),
            ]]);

            // DEBUG: Check config BEFORE $tenant->run()
            \Log::info('BEFORE $tenant->run()', [
                'template_connection' => config('tenancy.database.template_tenant_connection'),
                'config_tenant' => config('database.connections.tenant'),
                'config_tenant_is_null' => config('database.connections.tenant') === null,
                'all_connections' => array_keys(config('database.connections')),
            ]);

            // DEBUG: Test database()->connection() outside of tenant context
            try {
                $dbConfig = $tenant->database();
                \Log::info('DatabaseConfig created successfully', ['name' => $dbConfig->getName()]);
                
                $connection = $dbConfig->connection();
                \Log::info('Connection config generated', ['connection' => $connection]);
            } catch (\Exception $e) {
                \Log::warning('DatabaseConfig test failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $tenant->run(function () use ($request, $tenant, &$adminToken, $databaseName) {
                // MANUAL DATABASE CONNECTION (DatabaseTenancyBootstrapper disabled)
                // Set the tenant connection to use the newly created database
                config(['database.connections.tenant.database' => $databaseName]);
                DB::purge('tenant'); // Clear any cached connection
                DB::reconnect('tenant'); // Reconnect with new database
                DB::setDefaultConnection('tenant'); // Switch to tenant connection
                config(['database.default' => 'tenant']);
                
                \Log::info('Tenant DB connection established', [
                    'database' => $databaseName,
                    'default_connection' => DB::getDefaultConnection(),
                ]);
                
                // Run tenant migrations
                \Artisan::call('migrate', [
                    '--path'  => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                // Seed roles and permissions
                \Artisan::call('db:seed', [
                    '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                    '--force' => true,
                ]);

                // Create owner user in tenant DB
                $owner = User::create([
                    'name'              => $request->admin_name,
                    'email'             => $request->admin_email,
                    'password'          => Hash::make($request->admin_password),
                    'email_verified_at' => now(),
                    'role'              => 'Owner', // CRITICAL: Set role field for frontend isAdmin() check
                ]);

                // Assign Owner role (Spatie permissions)
                $owner->assignRole('Owner');

                // Create Technician record for Owner
                \App\Models\Technician::create([
                    'name'       => $owner->name,
                    'email'      => $owner->email,
                    'role'       => 'owner',
                    'phone'      => null,
                    'user_id'    => $owner->id,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);

                // Generate API token
                $adminToken = $owner->createToken('onboarding-token')->plainTextToken;
            });

            return response()->json([
                'status'   => 'ok',
                'message'  => 'Tenant created successfully',
                'tenant'   => $request->slug,
                'database' => $databaseName,
                'tenant_info' => [
                    'id'            => $tenant->id,
                    'slug'          => $tenant->slug,
                    'name'          => $tenant->name,
                    'domain'        => $request->slug . '.' . $baseDomain,
                    'status'        => $tenant->status,
                    'trial_ends_at' => optional($tenant->trial_ends_at)->toISOString(),
                ],
                'admin' => [
                    'email' => $request->admin_email,
                    'token' => $adminToken,
                ],
                'next_steps' => [
                    'login_url' => (app()->environment('local') ? 'http://' : 'https://') . $request->slug . '.' . $baseDomain . '/login',
                    'api_header' => 'X-Tenant: ' . $request->slug,
                ],
            ], 201);

        } catch (\Exception $e) {
            \Log::error('Tenant registration failed', [
                'slug'  => $request->slug ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            try {
                if (isset($tenant)) {
                    $tenant->delete();
                }
            } catch (\Exception $cleanupError) {
                \Log::error('Cleanup failed after tenant registration error', [
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Tenant registration failed',
                'error'   => app()->environment('local')
                    ? $e->getMessage()
                    : 'An error occurred during registration. Please try again.',
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
        $tenant = Tenant::where('slug', $slug)->first();
        $exists = (bool) $tenant;

        return response()->json([
            'available' => !$exists,
            'exists' => $exists,
            // Used by the login UI to enforce SSO-only tenants.
            'require_sso' => $exists ? (bool) $tenant->require_sso : null,
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

    /**
     * Toggle AI addon availability for a tenant.
     */
    public function updateAiToggle(UpdateTenantAiRequest $request, ?Tenant $tenant = null): JsonResponse
    {
        $tenant ??= tenancy()->tenant;

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant context is required to update AI preferences.',
            ], 400);
        }

        // AI add-on follows the same tenant-scoped control model as Planning.
        // No system-level role is required for tenant feature management.
        $tenant->update([
            'ai_enabled' => $request->boolean('ai_enabled'),
        ]);

        return response()->json([
            'tenant' => [
                'ai_enabled' => (bool) $tenant->ai_enabled,
            ],
        ]);
    }

    /**
     * Request tenant signup - creates pending signup and sends verification email.
     */
    public function requestSignup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255',
            // IMPORTANT: This endpoint is CENTRAL (no tenant context required).
            // A frontend request may still include X-Tenant (e.g. from <tenant>.localhost),
            // which can initialize tenancy and change the default DB connection to `tenant`.
            // Explicitly target the central connection to avoid querying `tenants` inside a tenant DB.
            'slug' => 'required|string|max:100|unique:mysql.tenants,slug|unique:mysql.pending_tenant_signups,slug|regex:/^[a-z0-9-]+$/',
            'admin_name' => 'required|string|max:255',
            'admin_email' => 'required|string|email|max:255|unique:mysql.pending_tenant_signups,admin_email',
            'admin_password' => 'required|string|min:8|confirmed',
            'industry' => 'nullable|string|max:100',
            'country' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:50',
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

        app(EmailPolicyService::class)->assertAllowedEmail(
            (string) $request->admin_email,
            $request,
            ['tenant_slug' => (string) $request->slug]
        );

        $captchaGate = app(CaptchaGate::class);
        $captchaGate->recordRegisterAttempt($request, 'tenants/request-signup', (string) $request->admin_email, (string) $request->slug);
        $captchaGate->assertCaptchaIfRequired($request, 'tenants/request-signup', (string) $request->admin_email, (string) $request->slug, 'request_signup_adaptive');

        try {
            // Clean up any expired pending signups for this email
            PendingTenantSignup::where('admin_email', $request->admin_email)
                ->where('expires_at', '<', now())
                ->delete();

            // Create pending signup
            $verificationToken = PendingTenantSignup::generateToken();
            
            $pendingSignup = PendingTenantSignup::create([
                'company_name' => $request->company_name,
                'slug' => $request->slug,
                'admin_name' => $request->admin_name,
                'admin_email' => $request->admin_email,
                'password_hash' => Hash::make($request->admin_password),
                'verification_token' => $verificationToken,
                'industry' => $request->industry,
                'country' => $request->country,
                'timezone' => $request->timezone ?? 'UTC',
                'expires_at' => Carbon::now()->addHours(24),
            ]);

            // Build verification URL
            $frontendUrl = config('app.frontend_url', config('app.url'));
            $verificationUrl = $frontendUrl . '/verify-signup?token=' . $verificationToken;

            // Send /verification email
            $recipient = new EmailRecipient($request->admin_email, $request->admin_name);
            $recipient->notify(new TenantEmailVerification($verificationUrl, $request->company_name));

            \Log::info('Pending tenant signup created', [
                'slug' => $request->slug,
                'expires_at' => $pendingSignup->expires_at,
            ]);

            $response = [
                'status' => 'pending',
                'message' => 'Verification email sent successfully',
                'email' => $request->admin_email,
                'expires_in_hours' => 24,
            ];

            // DEV/TEST helper: return the exact same link/token used in the email,
            // so the test button can copy/paste it reliably.
            if (app()->environment(['local', 'testing'])) {
                $response['verification_url'] = $verificationUrl;
                $response['verification_token'] = $verificationToken;
            }

            return response()->json($response, 200);

        } catch (\Exception $e) {
            \Log::error('Pending tenant signup failed', [
                'slug'  => $request->slug ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Handle SMTP 550 errors (invalid or unrouteable email address)
            if (str_contains($e->getMessage(), '550')) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'The email address was rejected by the mail server. Please check the email and try again.',
                ], 422);
            }

            // Generic fallback for unexpected errors
            return response()->json([
                'status'  => 'error',
                'message' => app()->environment('local')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during signup. Please try again.',
            ], 500);
        }
    }

    /**
     * Verify email and complete tenant registration.
     */
    public function verifySignup(Request $request): JsonResponse
    {
        $token = $request->input('token');

        if (!$token) {
            return response()->json([
                'message' => 'Verification token is required',
            ], 400);
        }

        try {
            // Find pending signup
            $pendingSignup = PendingTenantSignup::where('verification_token', $token)->first();

            if (!$pendingSignup) {
                return response()->json([
                    'message' => 'Invalid verification token',
                    'error' => 'not_found',
                ], 404);
            }

            // Check if already verified
            if ($pendingSignup->verified) {
                return response()->json([
                    'message' => 'This email has already been verified',
                    'error' => 'already_verified',
                ], 400);
            }

            // Check if expired
            if ($pendingSignup->isExpired()) {
                $pendingSignup->delete();
                return response()->json([
                    'message' => 'Verification link has expired. Please start the registration process again.',
                    'error' => 'expired',
                ], 400);
            }

            app(EmailPolicyService::class)->assertAllowedEmail(
                (string) $pendingSignup->admin_email,
                $request,
                ['tenant_slug' => (string) $pendingSignup->slug]
            );

            $captchaGate = app(CaptchaGate::class);
            $captchaGate->recordRegisterAttempt($request, 'tenants/verify-signup', (string) $pendingSignup->admin_email, (string) $pendingSignup->slug);
            $captchaGate->assertCaptchaIfRequired($request, 'tenants/verify-signup', (string) $pendingSignup->admin_email, (string) $pendingSignup->slug, 'verify_signup_adaptive');

            // If a previous verify attempt partially created the tenant, resume provisioning
            // instead of blocking the user with slug_taken.
            $existingTenant = Tenant::where('slug', $pendingSignup->slug)->first();
            if ($existingTenant) {
                if ($existingTenant->owner_email !== $pendingSignup->admin_email) {
                    return response()->json([
                        'message' => 'This workspace name is no longer available. Please start registration again with a different name.',
                        'error' => 'slug_taken',
                    ], 409);
                }

                $tenant = $this->ensureTenantProvisionedFromPendingSignup($existingTenant, $pendingSignup);
            } else {
                // All checks passed - create the tenant using existing logic
                $tenant = $this->createTenantFromPendingSignup($pendingSignup);
            }

            // Mark as verified and delete pending signup
            $pendingSignup->delete();

            \Log::info('Tenant created from verified signup', [
                'slug' => $tenant->slug,
            ]);

            // Build login URL
            $baseDomain = config('app.domain', 'localhost:8082');
            $loginUrl = (app()->environment('local') ? 'http://' : 'https://') 
                . $pendingSignup->slug . '.' . $baseDomain . '/login?email=' 
                . urlencode($pendingSignup->admin_email);

            return response()->json([
                'status' => 'success',
                'message' => 'Email verified successfully! Your workspace has been created.',
                'tenant' => [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'name' => $tenant->name,
                ],
                'login_url' => $loginUrl,
            ], 200);

        } catch (HttpResponseException $e) {
            // CaptchaGate uses HttpResponseException to short-circuit with a JSON response
            // (e.g. 422 captcha_required). Do not wrap it as a 500.
            throw $e;

        } catch (\Exception $e) {
            \Log::error('Email verification failed', [
                'token' => $token,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Verification failed',
                'error' => app()->environment('local')
                    ? $e->getMessage()
                    : 'An error occurred during verification. Please try again or contact support.',
            ], 500);
        }
    }

    /**
     * Create tenant from verified pending signup (extracted from register method).
     */
    private function createTenantFromPendingSignup(PendingTenantSignup $pendingSignup): Tenant
    {
        // Base domain for tenant URLs (frontend)
        $baseDomain = config('app.domain', 'localhost:3000');

        // 1. Create Tenant in central database
        $tenant = Tenant::create([
            'name'         => $pendingSignup->company_name,
            'slug'         => $pendingSignup->slug,
            'owner_email'  => $pendingSignup->admin_email,
            'status'       => 'active',
            'plan'         => 'trial',
            'timezone'     => $pendingSignup->timezone,
            'trial_ends_at'=> now()->addDays(14),
        ]);

        $tenant->refresh();

        // 2. Get database name
        $databaseName = $tenant->getInternal('db_name');

        // 3. Create tenant database
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // 4. Run tenant migrations (MUST be done before accessing tenant tables)
        try {
            Artisan::call('tenants:migrate', ['tenant' => $tenant->id]);
        } catch (\Exception $e) {
            \Log::error('Failed to run tenant migrations', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            // Continue anyway - migrations might have partially run
        }

        // 5. Create Company record in tenant database
        $tenant->run(function () use ($pendingSignup, $tenant) {
            Company::create([
                'tenant_id' => $tenant->id,
                'name'      => $pendingSignup->company_name,
                'industry'  => $pendingSignup->industry,
                'country'   => $pendingSignup->country,
                'timezone'  => $pendingSignup->timezone,
                'status'    => 'active',
            ]);
        });

        // 6. Start trial subscription (in central database)
        $planManager = app(PlanManager::class);
        $planManager->startTrialForTenant($tenant);

        // 7. Create Stripe customer if enabled
        if (config('payments.driver') === 'stripe') {
            try {
                \Stripe\Stripe::setApiKey(config('payments.stripe.secret_key'));
                
                $stripeCustomer = \Stripe\Customer::create([
                    'name' => $pendingSignup->company_name,
                    'email' => $pendingSignup->admin_email,
                    'metadata' => [
                        'tenant_id' => $tenant->id,
                        'slug' => $tenant->slug,
                    ],
                ]);

                \App\Models\BillingProfile::create([
                    'tenant_id' => $tenant->id,
                    'gateway' => 'stripe',
                    'stripe_customer_id' => $stripeCustomer->id,
                    'billing_email' => $pendingSignup->admin_email,
                    'billing_name' => $pendingSignup->company_name,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Stripe customer creation failed', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 7. Configure tenant connection
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'unix_socket' => config('database.connections.mysql.unix_socket'),
            'charset' => config('database.connections.mysql.charset'),
            'collation' => config('database.connections.mysql.collation'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => config('database.connections.mysql.options', []),
        ]]);

        // 8. Initialize tenant and create admin user
        $tenant->run(function () use ($pendingSignup, $tenant, $databaseName) {
            // Set tenant connection
            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');
            
            // Run migrations
            \Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant',
                '--force' => true,
            ]);

            // Seed roles and permissions
            \Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                '--force' => true,
            ]);

            // Create owner user
            $owner = User::create([
                'name'              => $pendingSignup->admin_name,
                'email'             => $pendingSignup->admin_email,
                'password'          => $pendingSignup->password_hash, // Already hashed
                'email_verified_at' => now(), // Email already verified
                'role'              => 'Owner',
            ]);

            $owner->assignRole('Owner');

            // Create Technician record
            \App\Models\Technician::create([
                'name'       => $owner->name,
                'email'      => $owner->email,
                'role'       => 'owner',
                'phone'      => null,
                'user_id'    => $owner->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]);
        });

        return $tenant;
    }

    /**
     * Resume tenant provisioning for an already-created tenant that matches the pending signup.
     * This makes the email verification flow robust to partial failures (e.g. DB permission issues).
     */
    private function ensureTenantProvisionedFromPendingSignup(Tenant $tenant, PendingTenantSignup $pendingSignup): Tenant
    {
        $tenant->refresh();

        $databaseName = $tenant->getInternal('db_name');

        DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            Artisan::call('tenants:migrate', ['tenant' => $tenant->id]);
        } catch (\Exception $e) {
            \Log::error('Failed to run tenant migrations (resume)', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Ensure trial subscription exists (central)
        if (!Subscription::where('tenant_id', $tenant->id)->exists()) {
            $planManager = app(PlanManager::class);
            $planManager->startTrialForTenant($tenant);
        }

        // Configure tenant connection
        config(['database.connections.tenant' => [
            'driver' => 'mysql',
            'host' => config('database.connections.mysql.host'),
            'port' => config('database.connections.mysql.port'),
            'database' => $databaseName,
            'username' => config('database.connections.mysql.username'),
            'password' => config('database.connections.mysql.password'),
            'unix_socket' => config('database.connections.mysql.unix_socket'),
            'charset' => config('database.connections.mysql.charset'),
            'collation' => config('database.connections.mysql.collation'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => config('database.connections.mysql.options', []),
        ]]);

        // Provision tenant DB (idempotently)
        $tenant->run(function () use ($pendingSignup, $tenant, $databaseName) {
            config(['database.connections.tenant.database' => $databaseName]);
            DB::purge('tenant');
            DB::reconnect('tenant');
            DB::setDefaultConnection('tenant');

            \Artisan::call('migrate', [
                '--path'  => 'database/migrations/tenant',
                '--force' => true,
            ]);

            \Artisan::call('db:seed', [
                '--class' => 'Database\\Seeders\\RolesAndPermissionsSeeder',
                '--force' => true,
            ]);

            // Create Company if missing
            if (!Company::where('tenant_id', $tenant->id)->exists()) {
                Company::create([
                    'tenant_id' => $tenant->id,
                    'name'      => $pendingSignup->company_name,
                    'industry'  => $pendingSignup->industry,
                    'country'   => $pendingSignup->country,
                    'timezone'  => $pendingSignup->timezone,
                    'status'    => 'active',
                ]);
            }

            // Create owner if missing
            if (!User::where('email', $pendingSignup->admin_email)->exists()) {
                $owner = User::create([
                    'name'              => $pendingSignup->admin_name,
                    'email'             => $pendingSignup->admin_email,
                    'password'          => $pendingSignup->password_hash,
                    'email_verified_at' => now(),
                    'role'              => 'Owner',
                ]);

                $owner->assignRole('Owner');

                \App\Models\Technician::create([
                    'name'       => $owner->name,
                    'email'      => $owner->email,
                    'role'       => 'owner',
                    'phone'      => null,
                    'user_id'    => $owner->id,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);
            }
        });

        return $tenant;
    }
}
