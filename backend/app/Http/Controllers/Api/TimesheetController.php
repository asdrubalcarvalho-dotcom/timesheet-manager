<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class TimesheetController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Verificar autorização usando Policy
        $this->authorize('viewAny', Timesheet::class);

        $query = Timesheet::with(['technician', 'project', 'task', 'location']);

        // For Technicians, only show their own timesheets
        if ($request->user()->hasRole('Technician')) {
            $technician = \App\Models\Technician::where('email', $request->user()->email)->first();
            if ($technician) {
                $query->where('technician_id', $technician->id);
            }
        }
        
        // For Managers, only show timesheets from projects they manage + their own timesheets
        if ($request->user()->hasRole('Manager')) {
            $managerProjectIds = \App\Models\Project::where('manager_id', $request->user()->id)->pluck('id')->toArray();
            
            // Get manager's own technician record if exists
            $managerTechnician = \App\Models\Technician::where('email', $request->user()->email)->first();
            
            $query->where(function ($q) use ($managerProjectIds, $managerTechnician) {
                $hasConditions = false;
                
                // Timesheets from projects they manage
                if (!empty($managerProjectIds)) {
                    $q->whereIn('project_id', $managerProjectIds);
                    $hasConditions = true;
                }
                
                // Plus their own timesheets if they are also a technician
                if ($managerTechnician) {
                    if ($hasConditions) {
                        $q->orWhere('technician_id', $managerTechnician->id);
                    } else {
                        $q->where('technician_id', $managerTechnician->id);
                    }
                    $hasConditions = true;
                }
                
                // If manager has no projects and is not a technician, return empty result
                if (!$hasConditions) {
                    $q->whereRaw('1 = 0'); // Forces empty result
                }
            });
        }

        // Filter by technician (managers and admins only)
        if ($request->has('technician_id') && $request->user()->hasAnyRole(['Manager', 'Admin'])) {
            $query->where('technician_id', $request->technician_id);
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        $timesheets = $query->orderBy('date', 'desc')->get();
        
        // Adicionar informações de permissões para cada timesheet
        $timesheetsWithPermissions = $timesheets->map(function ($timesheet) use ($request) {
            return [
                ...$timesheet->toArray(),
                'permissions' => [
                    'can_view' => $request->user()->can('view', $timesheet),
                    'can_edit' => $request->user()->can('update', $timesheet),
                    'can_delete' => $request->user()->can('delete', $timesheet),
                    'can_approve' => $request->user()->can('approve', $timesheet),
                    'can_reject' => $request->user()->can('reject', $timesheet),
                ]
            ];
        });

        return response()->json([
            'data' => $timesheetsWithPermissions,
            'user_permissions' => [
                'can_create_timesheets' => $request->user()->can('create-timesheets'),
                'can_view_all_timesheets' => $request->user()->hasAnyRole(['Manager', 'Admin']),
                'can_approve_timesheets' => $request->user()->can('approve-timesheets'),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(\App\Http\Requests\StoreTimesheetRequest $request): JsonResponse
    {
        // Verificar autorização usando Policy
        $this->authorize('create', Timesheet::class);

        $validated = $request->validated();

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'submitted';
        }

        // Find technician by authenticated user's email
        $technician = \App\Models\Technician::where('email', $request->user()->email)->first();
        
        if (!$technician) {
            return response()->json(['error' => 'Technician profile not found'], 404);
        }

        $validated['technician_id'] = $technician->id;

        try {
            $timesheet = Timesheet::create($validated);
            $timesheet->load(['technician', 'project', 'task', 'location']);
            
            return response()->json([
                'data' => $timesheet,
                'message' => 'Timesheet criado com sucesso!',
                'permissions' => [
                    'can_view' => $request->user()->can('view', $timesheet),
                    'can_edit' => $request->user()->can('update', $timesheet),
                    'can_delete' => $request->user()->can('delete', $timesheet),
                    'can_approve' => $request->user()->can('approve', $timesheet),
                    'can_reject' => $request->user()->can('reject', $timesheet),
                ]
            ], 201);
        } catch (\Exception $e) {
            // Check if it's a time overlap error from validation or database
            if (strpos($e->getMessage(), 'Time overlap') !== false || 
                strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                return response()->json([
                    'error' => 'Sobreposição de tempo detectada. Este período de tempo está em conflito com um registro existente.'
                ], 409);
            }
            return response()->json(['error' => 'Falha ao criar timesheet: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Timesheet $timesheet): JsonResponse
    {
        // Verificar autorização usando Policy
        $this->authorize('view', $timesheet);

        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json([
            'data' => $timesheet,
            'permissions' => [
                'can_view' => $request->user()->can('view', $timesheet),
                'can_edit' => $request->user()->can('update', $timesheet),
                'can_delete' => $request->user()->can('delete', $timesheet),
                'can_approve' => $request->user()->can('approve', $timesheet),
                'can_reject' => $request->user()->can('reject', $timesheet),
            ]
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Timesheet $timesheet): JsonResponse
    {
        \Log::info('Update request received', [
            'timesheet_id' => $timesheet->id,
            'request_data' => $request->all()
        ]);

        // Check if user owns this timesheet
        $user = $request->user();
        $technician = \App\Models\Technician::where('email', $user->email)->first();
        
        \Log::info('Update authorization check', [
            'user_email' => $user->email,
            'technician_found' => $technician ? $technician->id : null,
            'timesheet_technician_id' => $timesheet->technician_id
        ]);
        
        if (!$technician) {
            \Log::error('Technician not found for update');
            return response()->json(['error' => 'Technician not found'], 404);
        }
        
        if ($timesheet->technician_id !== $technician->id) {
            \Log::error('Unauthorized update attempt');
            return response()->json(['error' => 'Unauthorized - You can only edit your own timesheets'], 403);
        }

        // Only allow editing of submitted or rejected timesheets
        if (!$timesheet->canBeEdited()) {
            return response()->json(['error' => 'Cannot edit approved timesheets'], 403);
        }

        try {
            $validated = $request->validate([
                'project_id' => 'exists:projects,id',
                'task_id' => 'required|exists:tasks,id',
                'location_id' => 'required|exists:locations,id',
                'date' => 'date',
                'start_time' => 'nullable|date_format:H:i',
                'end_time' => 'nullable|date_format:H:i',
                'hours_worked' => 'numeric|min:0.25|max:24',
                'description' => 'nullable|string',
                'status' => ['nullable', 'string', Rule::in(['submitted', 'approved', 'rejected', 'closed'])]
            ]);

            \Log::info('Validation passed for update', ['validated_data' => $validated]);

            // Check for time overlaps if start_time and end_time are provided (excluding current timesheet)
            if (isset($validated['start_time']) && isset($validated['end_time'])) {
                $overlappingTimesheet = Timesheet::where('technician_id', $timesheet->technician_id)
                    ->where('date', $validated['date'] ?? $timesheet->date)
                    ->where('id', '!=', $timesheet->id) // Exclude current timesheet from check
                    ->where(function ($query) use ($validated) {
                        // Check for overlapping time periods
                        $query->where(function ($q) use ($validated) {
                            // Case 1: Updated entry starts within existing entry
                            $q->where('start_time', '<=', $validated['start_time'])
                              ->where('end_time', '>', $validated['start_time']);
                        })->orWhere(function ($q) use ($validated) {
                            // Case 2: Updated entry ends within existing entry
                            $q->where('start_time', '<', $validated['end_time'])
                              ->where('end_time', '>=', $validated['end_time']);
                        })->orWhere(function ($q) use ($validated) {
                            // Case 3: Updated entry completely contains existing entry
                            $q->where('start_time', '>=', $validated['start_time'])
                              ->where('end_time', '<=', $validated['end_time']);
                        });
                    })
                    ->whereNotNull('start_time')
                    ->whereNotNull('end_time')
                    ->first();

                if ($overlappingTimesheet) {
                    return response()->json([
                        'error' => 'Time overlap detected. This time period conflicts with an existing timesheet entry.'
                    ], 409);
                }
            }

            $timesheet->update($validated);
            $timesheet->load(['technician', 'project', 'task', 'location']);
            
            \Log::info('Timesheet updated successfully', ['timesheet_id' => $timesheet->id]);
            
            return response()->json($timesheet);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed for update', ['errors' => $e->errors()]);
            return response()->json(['error' => 'Validation failed', 'details' => $e->errors()], 422);
        } catch (\Exception $e) {
            \Log::error('Update failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Timesheet $timesheet): JsonResponse
    {
        // Check if user owns this timesheet
        $user = $request->user();
        $technician = \App\Models\Technician::where('email', $user->email)->first();
        
        if (!$technician) {
            return response()->json(['error' => 'Technician not found'], 404);
        }
        
        if ($timesheet->technician_id !== $technician->id) {
            return response()->json(['error' => 'Unauthorized - You can only delete your own timesheets'], 403);
        }

        if (!$timesheet->canBeEdited()) {
            return response()->json(['error' => 'Cannot delete approved timesheets'], 403);
        }

        $timesheet->delete();
        return response()->json(['message' => 'Timesheet deleted successfully']);
    }

    /**
     * Approve a timesheet (manager only)
     */
    public function approve(Timesheet $timesheet): JsonResponse
    {
        $this->authorize('approve', $timesheet);
        
        $timesheet->approve();
        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json($timesheet);
    }

    /**
     * Reject a timesheet (manager only)
     */
    public function reject(Request $request, Timesheet $timesheet): JsonResponse
    {
        $this->authorize('reject', $timesheet);
        
        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        $timesheet->reject($validated['reason']);
        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json($timesheet);
    }

    /**
     * Close a timesheet (payroll processed - no further edits allowed)
     */
    public function close(Timesheet $timesheet): JsonResponse
    {
        // Only managers can close timesheets
        $user = request()->user();
        if ($user->role !== 'Manager') {
            return response()->json(['error' => 'Only managers can close timesheets'], 403);
        }

        if (!$timesheet->canBeClosed()) {
            return response()->json(['error' => 'Only approved timesheets can be closed'], 400);
        }

        $timesheet->close();
        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json($timesheet);
    }

    /**
     * Reopen an approved timesheet (supervisor can allow edits again)
     */
    public function reopen(Timesheet $timesheet): JsonResponse
    {
        // Only managers can reopen timesheets
        $user = request()->user();
        if ($user->role !== 'Manager') {
            return response()->json(['error' => 'Only managers can reopen timesheets'], 403);
        }

        if (!$timesheet->canBeReopened()) {
            return response()->json(['error' => 'Only approved timesheets can be reopened'], 400);
        }

        $timesheet->reopen();
        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json($timesheet);
    }
}
