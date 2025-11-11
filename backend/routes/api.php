<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\Api\TimesheetController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SocialAuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SuggestionController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\AccessManagerController;
use App\Http\Controllers\Api\DashboardController;

// Health check route (public)
Route::get('health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now(),
        'app' => config('app.name'),
        'version' => '1.0.0'
    ]);
});

// Authentication routes with rate limiting
Route::middleware('throttle:login')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('auth/sso/{provider}/token', [SocialAuthController::class, 'exchangeToken']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
});

// Protected routes (require authentication) with general rate limiting
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Access management routes for admin UI
    Route::prefix('access')->group(function () {
        Route::get('users', [AccessManagerController::class, 'listUsers']);
        Route::get('roles', [AccessManagerController::class, 'listRoles']);
        Route::get('permissions', [AccessManagerController::class, 'indexPermissions']);
        Route::post('users/{user}/assign-role', [AccessManagerController::class, 'assignRole']);
        Route::post('users/{user}/remove-role', [AccessManagerController::class, 'removeRole']);
        Route::post('users/{user}/assign-permission', [AccessManagerController::class, 'assignPermission']);
        Route::post('users/{user}/remove-permission', [AccessManagerController::class, 'removePermission']);

        // Role-permission management endpoints for matrix UI
        Route::get('roles/{role}/permissions', [AccessManagerController::class, 'getRolePermissions']);
        Route::post('roles/{role}/assign-permission', [AccessManagerController::class, 'assignPermissionToRole']);
        Route::post('roles/{role}/remove-permission', [AccessManagerController::class, 'removePermissionFromRole']);
    });

    // Technicians - List visible technicians (all authenticated users)
    Route::get('technicians', [TechnicianController::class, 'index']);
    
    // Technicians - CRUD Admin only
    Route::middleware('permission:manage-technicians')->group(function () {
        Route::post('technicians', [TechnicianController::class, 'store']);
        Route::get('technicians/{technician}', [TechnicianController::class, 'show']);
        Route::put('technicians/{technician}', [TechnicianController::class, 'update']);
        Route::delete('technicians/{technician}', [TechnicianController::class, 'destroy']);
    });

    // Projects - manage-projects permission controls access to Project Management page
    Route::middleware('permission:manage-projects')->group(function () {
        Route::get('projects', [ProjectController::class, 'index']);
        Route::get('projects/{project}', [ProjectController::class, 'show']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    });

    // Timesheets - Granular permissions with rate limiting
    Route::get('timesheets', [TimesheetController::class, 'index'])->middleware('permission:view-timesheets');
    Route::post('timesheets', [TimesheetController::class, 'store'])->middleware(['permission:create-timesheets', 'throttle:create']);
    
    // Specific routes BEFORE parameterized routes
    Route::get('timesheets/manager-view', [TimesheetController::class, 'managerView'])->middleware('permission:approve-timesheets');
    Route::get('timesheets/pending-counts', [TimesheetController::class, 'pendingCounts']);
    Route::get('timesheets/pending', [TimesheetController::class, 'pending'])->middleware('permission:approve-timesheets');
    
    // Parameterized routes
    Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])->middleware('permission:view-timesheets');
    Route::get('timesheets/{timesheet}/validation', [TimesheetController::class, 'validation'])->middleware('permission:view-timesheets');
    Route::put('timesheets/{timesheet}', [TimesheetController::class, 'update'])->middleware(['can.edit.timesheets', 'throttle:edit']);
    Route::delete('timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->middleware(['can.edit.timesheets', 'throttle:delete']);
    
    // Timesheet workflow - Manager/Admin only with strict rate limiting
    Route::put('timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/close', [TimesheetController::class, 'close'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reopen', [TimesheetController::class, 'reopen'])->middleware(['permission:approve-timesheets', 'throttle:critical']);

    // Expenses - Granular permissions
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:view-expenses');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:create-expenses');
    
    // Specific routes BEFORE parameterized routes
    Route::get('expenses/pending', [ExpenseController::class, 'pending'])->middleware('permission:approve-expenses');
    
    // Parameterized routes
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:view-expenses');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('can.edit.expenses');
    Route::post('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('can.edit.expenses'); // For file uploads with FormData
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('can.edit.expenses');
    
    // Expense workflow - Manager/Admin only
    Route::put('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:approve-expenses');
    Route::put('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->middleware('permission:approve-expenses');
    
    // Expense workflow - Finance only
    Route::put('expenses/{expense}/approve-finance', [ExpenseController::class, 'approveByFinance'])->middleware('permission:approve-finance-expenses');
    Route::put('expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])->middleware('permission:mark-expenses-paid');

    // Tasks - View for all, manage for admins
    Route::get('tasks', [TaskController::class, 'index']);
    Route::get('tasks/{task}', [TaskController::class, 'show']);
    Route::get('projects/{project}/tasks', [TaskController::class, 'byProject']);
    Route::middleware('permission:manage-tasks')->group(function () {
        Route::post('tasks', [TaskController::class, 'store']);
        Route::put('tasks/{task}', [TaskController::class, 'update']);
        Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
    });

    // Locations - View for all, manage for admins
    Route::get('locations/active', [LocationController::class, 'active']);
    Route::get('locations', [LocationController::class, 'index']);
    Route::get('locations/{location}', [LocationController::class, 'show']);
    Route::middleware('permission:manage-locations')->group(function () {
        Route::post('locations', [LocationController::class, 'store']);
        Route::put('locations/{location}', [LocationController::class, 'update']);
        Route::delete('locations/{location}', [LocationController::class, 'destroy']);
    });

    // AI Suggestions
    Route::prefix('ai')->group(function () {
        Route::get('suggestions/timesheet', [SuggestionController::class, 'getTimesheetSuggestions']);
        Route::get('suggestions/access', [SuggestionController::class, 'getAccessSuggestions']);
        Route::post('suggestions/feedback', [SuggestionController::class, 'submitFeedback']);
        Route::get('status', [SuggestionController::class, 'getStatus']);
    });

    // Project Members Management - same permission as projects
    Route::middleware('permission:manage-projects')->group(function () {
        Route::prefix('projects/{project}')->group(function () {
            Route::get('members', [ProjectController::class, 'getMembers']);
            Route::get('user-roles', [ProjectController::class, 'getUserRoles']);
            Route::post('members', [ProjectController::class, 'addMember']);
            Route::put('members/{user}', [ProjectController::class, 'updateMember']);
            Route::delete('members/{user}', [ProjectController::class, 'removeMember']);
        });
    });

    // User-specific project and workflow routes
    Route::prefix('user')->group(function () {
        Route::get('projects', [TimesheetController::class, 'getUserProjects']);
        Route::get('expense-projects', [ExpenseController::class, 'getUserProjects']);
    });

    // Additional timesheet workflow methods
    Route::put('timesheets/{timesheet}/submit', [TimesheetController::class, 'submit'])->middleware(['can.edit.timesheets', 'throttle:create']);
    
    // Additional expense workflow methods  
    Route::put('expenses/{expense}/submit', [ExpenseController::class, 'submit'])->middleware('can.edit.expenses');

    // Dashboard - Statistics endpoints
    Route::get('dashboard/statistics', [DashboardController::class, 'getStatistics']);
    Route::get('dashboard/top-projects', [DashboardController::class, 'getTopProjects']);

    // Events - CRUD for planning events
    Route::apiResource('events', EventController::class);

    Route::prefix('planning')->group(function () {
        Route::get('projects', [\App\Http\Controllers\PlanningController::class, 'indexProjects']);
        Route::get('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'showProject']);
        Route::post('projects', [\App\Http\Controllers\PlanningController::class, 'storeProject']);
        Route::put('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'updateProject']);
        Route::delete('projects/{project}', [\App\Http\Controllers\PlanningController::class, 'destroyProject']);

        Route::get('tasks', [\App\Http\Controllers\PlanningController::class, 'indexTasks']);
        Route::get('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'showTask']);
        Route::post('tasks', [\App\Http\Controllers\PlanningController::class, 'storeTask']);
        Route::put('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'updateTask']);
        Route::delete('tasks/{task}', [\App\Http\Controllers\PlanningController::class, 'destroyTask']);

        Route::get('resources', [\App\Http\Controllers\PlanningController::class, 'indexResources']);
        Route::get('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'showResource']);
        Route::post('resources', [\App\Http\Controllers\PlanningController::class, 'storeResource']);
        Route::put('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'updateResource']);
        Route::delete('resources/{resource}', [\App\Http\Controllers\PlanningController::class, 'destroyResource']);

        Route::get('locations', [\App\Http\Controllers\PlanningController::class, 'indexLocations']);
        Route::get('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'showLocation']);
        Route::post('locations', [\App\Http\Controllers\PlanningController::class, 'storeLocation']);
        Route::put('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'updateLocation']);
        Route::delete('locations/{location}', [\App\Http\Controllers\PlanningController::class, 'destroyLocation']);
    });
});


// CORS preflight and global headers
Route::options('{any}', function (Request $request) {
    return response()->json(['status' => 'OK'])
        ->header('Access-Control-Allow-Origin', '*')
        ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
})->where('any', '.*');
