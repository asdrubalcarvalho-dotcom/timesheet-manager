<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Admin\AiMetricsController;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TimesheetController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\Api\SsoAuthController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantDataController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TravelSegmentController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\AiApprovalsController;
use App\Http\Controllers\Api\AiTimesheetController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\PublicContactController;
use App\Http\Controllers\CountryController;

Route::post('/public/contact', [PublicContactController::class, 'submit']);
/*
|--------------------------------------------------------------------------
| Central API Routes
|--------------------------------------------------------------------------
| These routes are accessible without tenant context - used for onboarding
| and system-level operations.
*/

// Health check routes (public)
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'app' => config('app.name'),
        'version' => '1.0.0'
    ]);
});

Route::get('healthz', fn() => response()->json(['status' => 'healthy']));
Route::get('readyz', fn() => response()->json(['status' => 'ready']));

// Stripe webhook (public - signature verified internally)
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook']);

// Tenant onboarding (no tenant context required)
Route::post('tenants/register', [TenantController::class, 'register'])
    ->middleware('throttle:public-auth');

Route::post('tenants/request-signup', [TenantController::class, 'requestSignup'])
    ->middleware('throttle:public-auth');

Route::get('tenants/verify-signup', [TenantController::class, 'verifySignup'])
    ->middleware('throttle:public-auth');

Route::post('tenants/complete-signup', [TenantController::class, 'completeSignup'])
    ->middleware('throttle:public-auth');

Route::get('tenants/check-slug', [TenantController::class, 'checkSlug'])
    ->middleware('throttle:30,1'); // 30 checks per minute

    // Simple ping without tenant context (must be outside tenant.initialize)
Route::get('tenants/ping', function () {
    return response()->json([
        'pong' => true,
        'timestamp' => now(),
    ]);
});
// Authentication routes (no tenant middleware - tenant identified via request body)
Route::middleware('throttle:login')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('auth/sso/{provider}/token', [SocialAuthController::class, 'exchangeToken']);
});

// Tenant management (system admin only)
Route::middleware(['auth:sanctum', 'role:Admin'])->group(function () {
    Route::get('tenants', [TenantController::class, 'index']);
    Route::get('tenants/{slug}', [TenantController::class, 'show']);

    Route::prefix('admin')->group(function () {
        Route::get('ai/metrics', [AiMetricsController::class, 'index'])->middleware('throttle:read');
        // Legacy system-admin endpoint kept for backwards compatibility (tenant flows use /api/billing/ai-toggle)
        Route::put('tenants/{tenant:slug}/ai', [TenantController::class, 'updateAiToggle'])
            ->middleware('throttle:edit');
    });
});

// Public Billing Routes (No auth/tenant required - used by frontend before login)
Route::prefix('billing')->group(function () {
    Route::get('gateway', [\App\Modules\Billing\Controllers\BillingController::class, 'getGatewayConfig']);
});

/*
|--------------------------------------------------------------------------
| Tenant-Scoped API Routes
|--------------------------------------------------------------------------
| These routes require tenant identification via X-Tenant header
*/

Route::middleware(['tenant.initialize', 'tenant.set-context'])->group(function () {
    // SSO-2: Account linking (authenticated)
    Route::post('auth/sso/{provider}/link/start', [SsoAuthController::class, 'linkStart'])
        ->middleware(['auth:sanctum', 'throttle:create']);

    // Special route for attachment download - uses custom auth via token query param
    // Must be OUTSIDE auth:sanctum group to avoid login redirect
    Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'downloadAttachment'])
        ->middleware(['auth.token', 'throttle:read']);

    // Protected routes with tenant context and authentication
    // Note: SetSanctumTenantConnection runs globally via bootstrap/app.php prependToGroup('api')
    Route::middleware(['auth:sanctum', 'tenant.auth', 'subscription.write', 'user.last_seen'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);

        // Billing Module Routes (New Architecture - Phase 1)
        Route::prefix('billing')->group(function () {
            Route::get('summary', [\App\Modules\Billing\Controllers\BillingController::class, 'summary']);
            Route::post('upgrade-plan', [\App\Modules\Billing\Controllers\BillingController::class, 'upgradePlan']);
            Route::post('schedule-downgrade', [\App\Modules\Billing\Controllers\BillingController::class, 'scheduleDowngrade']);
            Route::post('cancel-scheduled-downgrade', [\App\Modules\Billing\Controllers\BillingController::class, 'cancelScheduledDowngrade']);
            Route::post('toggle-addon', [\App\Modules\Billing\Controllers\BillingController::class, 'toggleAddon']);
            Route::put('ai-toggle', [TenantController::class, 'updateAiToggle'])->middleware('throttle:edit');
            Route::put('compliance-settings', [TenantController::class, 'updateComplianceSettings'])->middleware('throttle:edit');
            Route::post('licenses/increase', [\App\Modules\Billing\Controllers\BillingController::class, 'increaseLicenses'])->middleware('throttle:edit');
            Route::post('checkout/start', [\App\Modules\Billing\Controllers\BillingController::class, 'checkoutStart']);
            Route::post('checkout/confirm', [\App\Modules\Billing\Controllers\BillingController::class, 'checkoutConfirm']);
            
            // Phase 4: Customer Portal (self-service billing management)
            Route::get('portal', [\App\Modules\Billing\Controllers\BillingController::class, 'createPortalSession']);
            
            // Payment Methods (Stripe card management)
            Route::get('payment-methods/setup-intent', [\App\Modules\Billing\Controllers\PaymentMethodController::class, 'setupIntent'])->middleware('throttle:read');
            Route::get('payment-methods', [\App\Modules\Billing\Controllers\PaymentMethodController::class, 'index'])->middleware('throttle:read');
            Route::post('payment-methods/add', [\App\Modules\Billing\Controllers\PaymentMethodController::class, 'add'])->middleware('throttle:edit');
            Route::post('payment-methods/default', [\App\Modules\Billing\Controllers\PaymentMethodController::class, 'setDefault'])->middleware('throttle:edit');
            Route::delete('payment-methods/{paymentMethodId}', [\App\Modules\Billing\Controllers\PaymentMethodController::class, 'destroy'])->middleware('throttle:delete');

            // Phase 3: ERP Invoice Tracking
            Route::get('invoices/pending-erp', [\App\Modules\Billing\Controllers\BillingController::class, 'getPendingErpInvoices'])->middleware('throttle:read');
        });

        // Protected routes (require authentication) with general rate limiting
        Route::middleware('throttle:api')->group(function () {
            // Reports (Timesheets export only)
            Route::post('reports/timesheets/export', [ReportsController::class, 'exportTimesheets'])
                ->middleware(['permission:view-timesheets', 'throttle:read']);

            // Reports (Timesheets summary pivot)
            Route::post('reports/timesheets/summary', [ReportsController::class, 'timesheetSummary'])
                ->middleware(['permission:view-timesheets', 'throttle:read']);

            // Reports (Timesheets pivot grid)
            Route::post('reports/timesheets/pivot', [ReportsController::class, 'timesheetPivot'])
                ->middleware(['permission:view-timesheets', 'throttle:read']);

            // Reports (Timesheets pivot export)
            Route::post('reports/timesheets/pivot/export', [ReportsController::class, 'timesheetsPivotExport'])
                ->middleware(['permission:view-timesheets', 'throttle:read']);

            // Reports (Expenses export)
            Route::post('reports/expenses/export', [ReportsController::class, 'exportExpenses'])
                ->middleware(['permission:view-expenses', 'throttle:read']);

            // Reports (Expenses summary pivot)
            Route::post('reports/expenses/summary', [ReportsController::class, 'expenseSummary'])
                ->middleware(['permission:view-expenses', 'throttle:read']);

            // Reports (Approvals heatmap)
            Route::post('reports/approvals/heatmap', [ReportsController::class, 'approvalHeatmap'])
                ->middleware(['permission:approve-timesheets|approve-expenses', 'throttle:read']);

            // AI (Approvals interpretation)
            Route::post('ai/approvals/query', [AiApprovalsController::class, 'query'])
                ->middleware(['permission:approve-timesheets|approve-expenses', 'throttle:read']);

            // AI Timesheet Builder
            Route::post('ai/timesheet/preview', [AiTimesheetController::class, 'preview'])
                ->middleware(['permission:create-timesheets', 'throttle:ai-read']);
            Route::post('ai/timesheet/commit', [AiTimesheetController::class, 'commit'])
                ->middleware(['permission:create-timesheets', 'throttle:create']);

            // Access management routes for admin UI
            Route::prefix('access')->group(function () {
                Route::get('users', [\App\Http\Controllers\AccessManagerController::class, 'listUsers'])->middleware('throttle:read');
                Route::get('roles', [\App\Http\Controllers\AccessManagerController::class, 'listRoles'])->middleware('throttle:read');
                Route::get('permissions', [\App\Http\Controllers\AccessManagerController::class, 'indexPermissions'])->middleware('throttle:read');
                Route::post('users/{user}/assign-role', [\App\Http\Controllers\AccessManagerController::class, 'assignRole'])->middleware('throttle:edit');
                Route::post('users/{user}/remove-role', [\App\Http\Controllers\AccessManagerController::class, 'removeRole'])->middleware('throttle:edit');
                Route::post('users/{user}/assign-permission', [\App\Http\Controllers\AccessManagerController::class, 'assignPermission'])->middleware('throttle:edit');
                Route::post('users/{user}/remove-permission', [\App\Http\Controllers\AccessManagerController::class, 'removePermission'])->middleware('throttle:edit');

                // Role-permission management endpoints for matrix UI
                Route::get('roles/{role}/permissions', [\App\Http\Controllers\AccessManagerController::class, 'getRolePermissions'])->middleware('throttle:read');
                Route::post('roles/{role}/assign-permission', [\App\Http\Controllers\AccessManagerController::class, 'assignPermissionToRole'])->middleware('throttle:edit');
                Route::post('roles/{role}/remove-permission', [\App\Http\Controllers\AccessManagerController::class, 'removePermissionFromRole'])->middleware('throttle:edit');
            });

    // Technicians - List visible technicians (all authenticated users)
    Route::get('technicians', [TechnicianController::class, 'index'])->middleware('throttle:read');
    
    // Technicians - CRUD Admin only
    Route::middleware('permission:manage-technicians')->group(function () {
        Route::post('technicians', [TechnicianController::class, 'store'])->middleware('throttle:create');
        Route::get('technicians/{technician}', [TechnicianController::class, 'show'])->middleware('throttle:read');
        Route::put('technicians/{technician}', [TechnicianController::class, 'update'])->middleware('throttle:edit');
        Route::delete('technicians/{technician}', [TechnicianController::class, 'destroy'])->middleware('throttle:delete');
    });
    // Projects - manage-projects permission controls access to Project Management page
    Route::middleware(['tenant.bootstrapped', 'permission:manage-projects'])->group(function () {
        Route::get('projects', [ProjectController::class, 'index'])->middleware('throttle:read');
        Route::get('projects/{project}', [ProjectController::class, 'show'])->middleware('throttle:read');
        Route::post('projects', [ProjectController::class, 'store'])->middleware('throttle:create');
        Route::put('projects/{project}', [ProjectController::class, 'update'])->middleware('throttle:edit');
        Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->middleware('throttle:delete');
    });

    // Timesheets - Granular permissions with rate limiting
    Route::get('timesheets', [TimesheetController::class, 'index'])->middleware(['tenant.bootstrapped', 'permission:view-timesheets', 'throttle:read']);
    Route::post('timesheets', [TimesheetController::class, 'store'])->middleware(['tenant.bootstrapped', 'permission:create-timesheets', 'throttle:create']);
    
    // Specific routes BEFORE parameterized routes
    Route::get('timesheets/summary', [TimesheetController::class, 'summary'])->middleware(['tenant.bootstrapped', 'permission:view-timesheets', 'throttle:read']);
    Route::get('timesheets/week', [TimesheetController::class, 'week'])->middleware(['tenant.bootstrapped', 'permission:view-timesheets', 'throttle:read']);
    Route::get('timesheets/manager-view', [TimesheetController::class, 'managerView'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:read']);
    Route::get('timesheets/pending-counts', [TimesheetController::class, 'pendingCounts'])->middleware(['tenant.bootstrapped', 'throttle:read']);
    Route::get('timesheets/pending', [TimesheetController::class, 'pending'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:read']);
    
    // Parameterized routes
    Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])->middleware(['tenant.bootstrapped', 'permission:view-timesheets', 'throttle:read']);
    Route::get('timesheets/{timesheet}/validation', [TimesheetController::class, 'validation'])->middleware(['tenant.bootstrapped', 'permission:view-timesheets', 'throttle:read']);
    Route::put('timesheets/{timesheet}', [TimesheetController::class, 'update'])->middleware(['tenant.bootstrapped', 'can.edit.timesheets', 'throttle:edit']);
    Route::delete('timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->middleware(['tenant.bootstrapped', 'can.edit.timesheets', 'throttle:delete']);
    
    // Timesheet workflow - Manager/Admin only with strict rate limiting
    Route::put('timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/close', [TimesheetController::class, 'close'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reopen', [TimesheetController::class, 'reopen'])->middleware(['tenant.bootstrapped', 'permission:approve-timesheets', 'throttle:critical']);

    // Expenses - Granular permissions
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware(['tenant.bootstrapped', 'permission:view-expenses', 'throttle:read']);
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware(['tenant.bootstrapped', 'permission:create-expenses', 'throttle:create']);
    
    // Specific routes MUST come BEFORE parameterized routes
    Route::get('expenses/pending', [ExpenseController::class, 'pending'])->middleware(['tenant.bootstrapped', 'permission:approve-expenses', 'throttle:read']);
    
    // Expense workflow actions - BEFORE {expense} routes
    Route::put('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware(['tenant.bootstrapped', 'permission:approve-expenses', 'throttle:critical']);
    Route::put('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware(['tenant.bootstrapped', 'permission:approve-expenses', 'throttle:critical']);
    Route::put('expenses/{expense}/approve-finance', [ExpenseController::class, 'approveByFinance'])->middleware(['tenant.bootstrapped', 'permission:approve-finance-expenses', 'throttle:critical']);
    Route::put('expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->middleware(['tenant.bootstrapped', 'permission:mark-expenses-paid', 'throttle:critical']);
    Route::put('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware(['tenant.bootstrapped', 'can.edit.expenses', 'throttle:create']);
    
    // Generic parameterized routes - MUST come LAST
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware(['tenant.bootstrapped', 'permission:view-expenses', 'throttle:read']);
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware(['tenant.bootstrapped', 'can.edit.expenses', 'throttle:edit']);
    Route::post('expenses/{expense}', [ExpenseController::class, 'update'])->middleware(['tenant.bootstrapped', 'can.edit.expenses', 'throttle:edit']); // For file uploads with FormData
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware(['tenant.bootstrapped', 'can.edit.expenses', 'throttle:delete']);

    // Tasks - View for all, manage for admins
    Route::get('tasks', [\App\Http\Controllers\TaskController::class, 'index'])->middleware('throttle:read');
    Route::get('tasks/{task}', [\App\Http\Controllers\TaskController::class, 'show'])->middleware('throttle:read');
    Route::get('projects/{project}/tasks', [\App\Http\Controllers\TaskController::class, 'byProject'])->middleware(['tenant.bootstrapped', 'throttle:read']);
    Route::middleware('permission:manage-tasks')->group(function () {
        Route::post('tasks', [\App\Http\Controllers\TaskController::class, 'store'])->middleware('throttle:create');
        Route::put('tasks/{task}', [\App\Http\Controllers\TaskController::class, 'update'])->middleware('throttle:edit');
        Route::delete('tasks/{task}', [\App\Http\Controllers\TaskController::class, 'destroy'])->middleware('throttle:delete');
    });

    Route::apiResource('countries', CountryController::class)
        ->only(['index', 'show', 'store', 'update', 'destroy']);

    // Locations - View for all, manage for admins
    Route::get('locations/active', [\App\Http\Controllers\LocationController::class, 'active'])->middleware('throttle:read');
    Route::get('locations', [\App\Http\Controllers\LocationController::class, 'index'])->middleware('throttle:read');
    Route::get('locations/{location}', [\App\Http\Controllers\LocationController::class, 'show'])->middleware('throttle:read');
    Route::middleware('permission:manage-locations')->group(function () {
        Route::post('locations', [\App\Http\Controllers\LocationController::class, 'store'])->middleware('throttle:create');
        Route::put('locations/{location}', [\App\Http\Controllers\LocationController::class, 'update'])->middleware('throttle:edit');
        Route::delete('locations/{location}', [\App\Http\Controllers\LocationController::class, 'destroy'])->middleware('throttle:delete');
    });

    // AI Suggestions - Requires Enterprise plan + AI addon
    Route::prefix('ai')->middleware('module:ai')->group(function () {
        Route::get('suggestions/timesheet', [\App\Http\Controllers\SuggestionController::class, 'getTimesheetSuggestions'])->middleware('throttle:read');
        Route::get('suggestions/access', [\App\Http\Controllers\SuggestionController::class, 'getAccessSuggestions'])->middleware('throttle:read');
        Route::post('suggestions/feedback', [\App\Http\Controllers\SuggestionController::class, 'submitFeedback'])->middleware('throttle:create');
        Route::get('status', [\App\Http\Controllers\SuggestionController::class, 'getStatus'])->middleware('throttle:read');
    });

    // Tenant Data Management - OWNER ONLY
    Route::post('tenant/reset-data', [TenantDataController::class, 'resetData'])
        ->middleware(['throttle:critical']); // Only Owner can access (enforced in controller)

    // Project Members Management - same permission as projects
    Route::middleware(['tenant.bootstrapped', 'permission:manage-projects'])->group(function () {
        Route::prefix('projects/{project}')->group(function () {
            Route::get('members', [ProjectController::class, 'getMembers'])->middleware('throttle:read');
            Route::get('user-roles', [ProjectController::class, 'getUserRoles'])->middleware('throttle:read');
            Route::post('members', [ProjectController::class, 'addMember'])->middleware('throttle:create');
            Route::put('members/{user}', [ProjectController::class, 'updateMember'])->middleware('throttle:edit');
            Route::delete('members/{user}', [ProjectController::class, 'removeMember']);
        });
    });

    // User-specific project and workflow routes
    Route::prefix('user')->group(function () {
        Route::get('projects', [TimesheetController::class, 'getUserProjects']);
        Route::get('expense-projects', [ExpenseController::class, 'getUserProjects']);
    });

    // Additional timesheet workflow methods
    Route::put('timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->middleware(['tenant.bootstrapped', 'can.edit.timesheets', 'throttle:create']);

    // Dashboard - Statistics endpoints
    Route::get('dashboard/statistics', [DashboardController::class, 'getStatistics'])->middleware('throttle:read');
    Route::get('dashboard/top-projects', [DashboardController::class, 'getTopProjects'])->middleware('throttle:read');

    // Events - CRUD for planning events (permission model mirrors Timesheets)
    Route::get('events', [EventController::class, 'index'])->middleware(['permission:view-planning', 'throttle:read']);
    Route::post('events', [EventController::class, 'store'])->middleware(['permission:create-planning', 'throttle:create']);
    Route::get('events/{event}', [EventController::class, 'show'])->middleware(['permission:view-planning', 'throttle:read']);
    Route::put('events/{event}', [EventController::class, 'update'])->middleware(['can_edit_planning', 'throttle:edit']);
    Route::delete('events/{event}', [EventController::class, 'destroy'])->middleware(['can_edit_planning', 'throttle:delete']);

    // Travel Segments - Requires Team/Enterprise plan or Starter with >2 users
    Route::prefix('travels')->middleware(['tenant.bootstrapped', 'module:travels'])->group(function () {
        Route::get('/', [TravelSegmentController::class, 'index'])->middleware('throttle:read');
        Route::post('/', [TravelSegmentController::class, 'store'])->middleware(['permission:create-timesheets', 'throttle:create']);
        Route::get('/by-date', [TravelSegmentController::class, 'getTravelsByDate'])->middleware('throttle:read'); // Timesheet integration
        Route::get('/suggestions', [TravelSegmentController::class, 'suggest'])->middleware('throttle:read');
        Route::get('/{travelSegment}', [TravelSegmentController::class, 'show'])->middleware('throttle:read');
        Route::put('/{travelSegment}', [TravelSegmentController::class, 'update'])->middleware(['permission:edit-own-timesheets', 'throttle:edit']);
        Route::delete('/{travelSegment}', [TravelSegmentController::class, 'destroy'])->middleware(['permission:edit-own-timesheets', 'throttle:delete']);
    });

    // Planning Module - Requires Team/Enterprise plan + Planning addon
    Route::prefix('planning')->middleware(['tenant.bootstrapped', 'module:planning'])->group(function () {
        Route::get('projects', [\App\Http\Controllers\PlanningController::class, 'indexProjects'])->middleware(['permission:view-planning', 'throttle:read']);
        Route::get('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'showProject'])->middleware('permission:view-planning');
        Route::post('projects', [\App\Http\Controllers\PlanningController::class, 'storeProject'])->middleware('permission:create-planning');
        Route::put('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'updateProject'])->middleware('can_edit_planning');
        Route::delete('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'destroyProject'])->middleware('can_edit_planning');

        Route::get('tasks', [\App\Http\Controllers\PlanningController::class, 'indexTasks'])->middleware('permission:view-planning');
        Route::get('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'showTask'])->middleware('permission:view-planning');
        Route::post('tasks', [\App\Http\Controllers\PlanningController::class, 'storeTask'])->middleware('permission:create-planning');
        Route::put('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'updateTask'])->middleware('can_edit_planning');
        Route::delete('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'destroyTask'])->middleware('can_edit_planning');

        Route::get('resources', [\App\Http\Controllers\PlanningController::class, 'indexResources'])->middleware('permission:view-planning');
        Route::get('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'showResource'])->middleware('permission:view-planning');
        Route::post('resources', [\App\Http\Controllers\PlanningController::class, 'storeResource'])->middleware('permission:create-planning');
        Route::put('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'updateResource'])->middleware('can_edit_planning');
        Route::delete('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'destroyResource'])->middleware('can_edit_planning');

        Route::get('locations', [\App\Http\Controllers\PlanningController::class, 'indexLocations'])->middleware('permission:view-planning');
        Route::get('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'showLocation'])->middleware('permission:view-planning');
        Route::post('locations', [\App\Http\Controllers\PlanningController::class, 'storeLocation'])->middleware('permission:create-planning');
        Route::put('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'updateLocation'])->middleware('can_edit_planning');
        Route::delete('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'destroyLocation'])->middleware('can_edit_planning');
    });

    // Task-Location Management (outside planning middleware - available for all)
    Route::prefix('tasks/{task}/locations')->group(function () {
        Route::get('/', [\App\Http\Controllers\PlanningController::class, 'getTaskLocations'])->middleware(['permission:view-planning', 'throttle:read']);
        Route::post('/', [\App\Http\Controllers\PlanningController::class, 'attachLocations'])->middleware(['can_edit_planning', 'throttle:edit']);
        Route::delete('/{location}', [\App\Http\Controllers\PlanningController::class, 'detachLocation'])->middleware(['can_edit_planning', 'throttle:delete']);
    });

    // Admin-only: Reset tenant data (preserves Owner user)
    Route::post('admin/reset-data', [TenantDataController::class, 'resetData'])
        ->middleware(['role:Owner|Admin', 'throttle:critical']);
    });
    });
});

// =============================================================================
// TELEMETRY API ROUTES (Internal - Protected by X-Internal-Api-Key)
// =============================================================================
Route::prefix('admin/telemetry')
    ->middleware(['telemetry.internal'])
    ->group(function () {
        Route::get('info', [\App\Http\Controllers\Admin\TelemetryController::class, 'info']);
        Route::get('tenants', [\App\Http\Controllers\Admin\TelemetryController::class, 'tenants']);
        Route::get('billing', [\App\Http\Controllers\Admin\TelemetryController::class, 'billing']);
        Route::get('usage', [\App\Http\Controllers\Admin\TelemetryController::class, 'usage']);
        Route::get('errors', [\App\Http\Controllers\Admin\TelemetryController::class, 'errors']);
        Route::get('health', [\App\Http\Controllers\Admin\TelemetryController::class, 'health']);
        Route::get('ping', [\App\Http\Controllers\Admin\TelemetryController::class, 'ping']);
    });

// =============================================================================
// SUPERADMIN TELEMETRY API (Frontend-facing - Sanctum + Domain + Role)
// =============================================================================
// CORS preflight for superadmin routes (must be BEFORE authenticated routes)
Route::match(['options'], 'superadmin/telemetry/info', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/tenants', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/billing', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/usage', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/errors', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/health', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/ping', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/tenants/{slug}/usage', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/tenants/{slug}/billing-details', fn() => response('', 204));
Route::match(['options'], 'superadmin/telemetry/tenants/{slug}/delete', fn() => response('', 204));

Route::prefix('superadmin/telemetry')
    ->middleware(['telemetry.superadmin'])
    ->group(function () {
        Route::get('info', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'info']);
        Route::get('tenants', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'tenants']);
        Route::get('billing', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'billing']);
        Route::get('usage', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'usage']);
        Route::get('errors', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'errors']);
        Route::get('health', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'health']);
        Route::get('ping', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'ping']);

        // Management-only tenant drilldown/actions
        Route::get('tenants/{slug}/usage', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'tenantUsage']);
        Route::get('tenants/{slug}/billing-details', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'tenantBillingDetails']);
        Route::post('tenants/{slug}/delete', [\App\Http\Controllers\Admin\SuperAdminTelemetryController::class, 'deleteTenant'])
            ->middleware('throttle:5,1');
    });

// CORS preflight and global headers
Route::options('{any}', function (Request $request) {
    return response()->json(['status' => 'OK'])
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
})->where('any', '.*');
