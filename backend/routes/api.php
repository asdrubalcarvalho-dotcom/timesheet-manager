<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TechnicianController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TimesheetController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\SuggestionController;

// Authentication routes with rate limiting
Route::middleware('throttle:login')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('user', [AuthController::class, 'user']);
});

// Protected routes (require authentication) with general rate limiting
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    // Technicians - Admin only
    Route::middleware('permission:manage-technicians')->group(function () {
        Route::apiResource('technicians', TechnicianController::class);
    });

    // Projects - View for all, manage for admins/managers
    Route::get('projects', [ProjectController::class, 'index']);
    Route::get('projects/{project}', [ProjectController::class, 'show']);
    Route::middleware('permission:manage-projects')->group(function () {
        Route::post('projects', [ProjectController::class, 'store']);
        Route::put('projects/{project}', [ProjectController::class, 'update']);
        Route::delete('projects/{project}', [ProjectController::class, 'destroy']);
    });

    // Timesheets - Granular permissions with rate limiting
    Route::get('timesheets', [TimesheetController::class, 'index'])->middleware('permission:view-timesheets');
    Route::post('timesheets', [TimesheetController::class, 'store'])->middleware(['permission:create-timesheets', 'throttle:create']);
    Route::get('timesheets/{timesheet}', [TimesheetController::class, 'show'])->middleware('permission:view-timesheets');
    Route::put('timesheets/{timesheet}', [TimesheetController::class, 'update'])->middleware(['permission:edit-timesheets', 'throttle:edit']);
    Route::delete('timesheets/{timesheet}', [TimesheetController::class, 'destroy'])->middleware(['permission:delete-timesheets', 'throttle:delete']);
    
    // Timesheet workflow - Manager/Admin only with strict rate limiting
    Route::get('timesheets/pending', [TimesheetController::class, 'pending'])->middleware('permission:approve-timesheets');
    Route::put('timesheets/{timesheet}/approve', [TimesheetController::class, 'approve'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reject', [TimesheetController::class, 'reject'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/close', [TimesheetController::class, 'close'])->middleware(['permission:approve-timesheets', 'throttle:critical']);
    Route::put('timesheets/{timesheet}/reopen', [TimesheetController::class, 'reopen'])->middleware(['permission:approve-timesheets', 'throttle:critical']);

    // Expenses - Granular permissions
    Route::get('expenses', [ExpenseController::class, 'index'])->middleware('permission:view-expenses');
    Route::post('expenses', [ExpenseController::class, 'store'])->middleware('permission:create-expenses');
    Route::get('expenses/{expense}', [ExpenseController::class, 'show'])->middleware('permission:view-expenses');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->middleware('permission:edit-expenses');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->middleware('permission:delete-expenses');
    
    // Expense workflow - Manager/Admin only
    Route::get('expenses/pending', [ExpenseController::class, 'pending'])->middleware('permission:approve-expenses');
    Route::put('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->middleware('permission:approve-expenses');

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
        Route::post('suggestions/feedback', [SuggestionController::class, 'submitFeedback']);
        Route::get('status', [SuggestionController::class, 'getStatus']);
    });
});

// CORS preflight
Route::options('{any}', function (Request $request) {
    return response()->json(['status' => 'OK']);
})->where('any', '.*');