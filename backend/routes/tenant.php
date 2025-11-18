<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the RouteServiceProvider within a tenant context.
|
| Middleware applied:
| - tenant.init.request (initializes tenancy by X-Tenant header or ?tenant parameter)
| - All routes require valid tenant identification
|
*/

Route::middleware([
    'api',
    InitializeTenancyByRequestData::class,
])->prefix('api')->group(function () {
    
    // Auth routes (tenant-scoped)
    Route::post('/login', [App\Http\Controllers\Api\AuthController::class, 'login']);
    Route::post('/register', [App\Http\Controllers\Api\AuthController::class, 'register']);
    Route::post('/logout', [App\Http\Controllers\Api\AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('/user', [App\Http\Controllers\Api\AuthController::class, 'user'])->middleware('auth:sanctum');

    // Protected tenant routes
    Route::middleware(['auth:sanctum'])->group(function () {
        
        // Dashboard
        Route::get('/dashboard/statistics', [App\Http\Controllers\Api\DashboardController::class, 'getStatistics']);
        Route::get('/dashboard/top-projects', [App\Http\Controllers\Api\DashboardController::class, 'topProjects']);

        // Projects
        Route::apiResource('projects', App\Http\Controllers\Api\ProjectController::class);
        Route::post('/projects/{project}/members', [App\Http\Controllers\Api\ProjectController::class, 'addMember'])
            ->middleware('can.manage.project.members');
        Route::put('/projects/{project}/members/{user}', [App\Http\Controllers\Api\ProjectController::class, 'updateMember'])
            ->middleware('can.manage.project.members');
        Route::delete('/projects/{project}/members/{user}', [App\Http\Controllers\Api\ProjectController::class, 'removeMember'])
            ->middleware('can.manage.project.members');

        // Technicians
        Route::apiResource('technicians', App\Http\Controllers\Api\TechnicianController::class);

        // Timesheets
        Route::apiResource('timesheets', App\Http\Controllers\Api\TimesheetController::class);
        Route::post('/timesheets/{timesheet}/approve', [App\Http\Controllers\Api\TimesheetController::class, 'approve'])
            ->middleware(['permission:approve-timesheets', 'throttle:critical']);
        Route::post('/timesheets/{timesheet}/reject', [App\Http\Controllers\Api\TimesheetController::class, 'reject'])
            ->middleware(['permission:approve-timesheets', 'throttle:critical']);
        Route::post('/timesheets/{timesheet}/close', [App\Http\Controllers\Api\TimesheetController::class, 'close'])
            ->middleware(['permission:close-timesheets', 'throttle:critical']);

        // Expenses
        Route::apiResource('expenses', App\Http\Controllers\Api\ExpenseController::class);
        Route::post('/expenses/{expense}/approve', [App\Http\Controllers\Api\ExpenseController::class, 'approve'])
            ->middleware(['permission:approve-expenses', 'throttle:critical']);
        Route::post('/expenses/{expense}/reject', [App\Http\Controllers\Api\ExpenseController::class, 'reject'])
            ->middleware(['permission:approve-expenses', 'throttle:critical']);
        Route::post('/expenses/{expense}/finance-approve', [App\Http\Controllers\Api\ExpenseController::class, 'financeApprove'])
            ->middleware(['permission:approve-finance-expenses', 'throttle:critical']);
        Route::post('/expenses/{expense}/mark-paid', [App\Http\Controllers\Api\ExpenseController::class, 'markPaid'])
            ->middleware(['permission:mark-expenses-paid', 'throttle:critical']);

        // Tasks & Locations (controllers in root namespace, not Api/)
        Route::apiResource('tasks', App\Http\Controllers\TaskController::class);
        Route::apiResource('locations', App\Http\Controllers\LocationController::class);

        // AI Suggestions (controller in root namespace)
        Route::get('/suggestions/timesheet', [App\Http\Controllers\SuggestionController::class, 'suggestTimesheet']);

        // Planning (controller in root namespace)
        Route::get('/planning/gantt', [App\Http\Controllers\PlanningController::class, 'gantt']);
        Route::post('/planning/gantt/update', [App\Http\Controllers\PlanningController::class, 'updateGantt']);

        // Events
        Route::apiResource('events', App\Http\Controllers\Api\EventController::class);
    });
});
