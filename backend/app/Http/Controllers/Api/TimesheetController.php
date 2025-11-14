<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use App\Models\Timesheet;
use App\Services\TimesheetValidation\TimesheetValidationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class TimesheetController extends Controller
{
    public function __construct(
        private readonly TimesheetValidationService $validationService
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Verificar autorização usando Policy
        $this->authorize('viewAny', Timesheet::class);

        $query = Timesheet::with(['technician', 'project', 'task', 'location']);

        // For Technicians (users with Technician role who are NOT project managers)
        // Only show their own timesheets
        if ($request->user()->hasRole('Technician') && !$request->user()->isProjectManager()) {
            $technician = \App\Models\Technician::where('email', $request->user()->email)->first();
            if ($technician) {
                $query->where('technician_id', $technician->id);
            }
        }
        
        // For Project Managers, show timesheets from:
        // 1. Projects where they are the manager (via manager_id or project_members)
        // 2. Their own timesheets if they are also a technician  
        // 3. Only show timesheets from 'member' technicians (NOT other managers)
        elseif ($request->user()->isProjectManager()) {
            $user = $request->user();
            $allManagedProjectIds = $user->getManagedProjectIds();
            $managerTechnician = \App\Models\Technician::where('user_id', $user->id)->first();
            
            $query->where(function ($q) use ($allManagedProjectIds, $managerTechnician, $user) {
                // Always include manager's own timesheets if they are also a technician
                if ($managerTechnician) {
                    $q->where('technician_id', $managerTechnician->id);
                }
                
                // Include timesheets from managed projects, but ONLY from 'member' technicians
                if (!empty($allManagedProjectIds)) {
                    $q->orWhere(function ($projectQuery) use ($allManagedProjectIds, $user) {
                        $projectQuery->whereIn('project_id', $allManagedProjectIds)
                            ->whereHas('technician', function ($techQuery) use ($allManagedProjectIds, $user) {
                                // Technician must have a user_id
                                $techQuery->whereNotNull('user_id')
                                    // AND that user must have project_role='member' in one of the managed projects
                                    ->whereHas('user.memberRecords', function ($memberQuery) use ($allManagedProjectIds) {
                                        $memberQuery->whereIn('project_id', $allManagedProjectIds)
                                            ->where('project_role', 'member');
                                    });
                            });
                    });
                }
                
                // If no conditions were added, force empty result
                if (!$managerTechnician && empty($allManagedProjectIds)) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        // Filter by technician (project managers and admins only)
        if ($request->has('technician_id') && ($request->user()->isProjectManager() || $request->user()->hasRole('Admin'))) {
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
            try {
                $canView = $request->user()->can('view', $timesheet);
                $canEdit = $request->user()->can('update', $timesheet);
                $canDelete = $request->user()->can('delete', $timesheet);
                $canApprove = $request->user()->can('approve', $timesheet);
                $canReject = $request->user()->can('reject', $timesheet);
            } catch (\App\Exceptions\UnauthorizedException $e) {
                // Se a policy lançar exceção, considerar como sem permissão
                $canView = false;
                $canEdit = false;
                $canDelete = false;
                $canApprove = false;
                $canReject = false;
            }
            
            return [
                ...$timesheet->toArray(),
                'permissions' => [
                    'can_view' => $canView,
                    'can_edit' => $canEdit,
                    'can_delete' => $canDelete,
                    'can_approve' => $canApprove,
                    'can_reject' => $canReject,
                ]
            ];
        });

        return response()->json([
            'data' => $timesheetsWithPermissions,
            'user_permissions' => [
                'can_create_timesheets' => $request->user()->hasPermissionTo('create-timesheets'),
                'can_view_all_timesheets' => $request->user()->hasAnyRole(['Manager', 'Admin']),
                'can_approve_timesheets' => $request->user()->hasPermissionTo('approve-timesheets'),
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

        $project = Project::with('memberRecords')->findOrFail($validated['project_id']);
        $isAdmin = $request->user()->hasRole('Admin');
        $isProjectManager = $project->isUserProjectManager($request->user());

        if (!$isAdmin && !$project->isUserMember($request->user())) {
            return response()->json([
                'error' => 'You are not assigned to this project.'
            ], 403);
        }

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'draft';
        }

        $technicianFromRequest = null;

        // Determine technician_id based on user role and request data
        if ($request->has('technician_id') && $validated['technician_id']) {
            if ($isAdmin || $isProjectManager) {
                // Admins and Project Managers can create timesheets for other technicians
                // Verify the technician exists
                $technicianFromRequest = Technician::find($validated['technician_id']);
                if (!$technicianFromRequest) {
                    return response()->json(['error' => 'Worker not found'], 404);
                }
                
                if ($technicianFromRequest->user && !$project->memberRecords()
                        ->where('user_id', $technicianFromRequest->user->id)->exists()) {
                    return response()->json([
                        'error' => 'This worker is not a member of the selected project.'
                    ], 422);
                }
            } else {
                // Regular users can only create timesheets for themselves
                // Override with their own technician_id
                $technicianFromRequest = Technician::where('email', $request->user()->email)->first();
                if (!$technicianFromRequest) {
                    return response()->json(['error' => 'Technician profile not found'], 404);
                }
                $validated['technician_id'] = $technicianFromRequest->id;
            }
        } else {
            // No technician_id provided, use authenticated user's technician
            $technicianFromRequest = Technician::where('email', $request->user()->email)->first();
            if (!$technicianFromRequest) {
                return response()->json(['error' => 'Technician profile not found'], 404);
            }
            $validated['technician_id'] = $technicianFromRequest->id;
        }

        if ($technicianFromRequest) {
            $technicianUserId = $technicianFromRequest->user_id;

            if (!$technicianUserId && $technicianFromRequest->email) {
                $relatedUser = User::where('email', $technicianFromRequest->email)->first();
                $technicianUserId = $relatedUser?->id;
            }

            if ($technicianUserId && !$project->memberRecords()->where('user_id', $technicianUserId)->exists()) {
                return response()->json([
                    'error' => 'This worker is not assigned to the selected project.'
                ], 403);
            }
        }

        try {
            \Log::info('💾 Creating timesheet', [
                'technician_id' => $validated['technician_id'],
                'date' => $validated['date'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'project_id' => $validated['project_id']
            ]);
            
            $timesheet = Timesheet::create($validated);
            
            \Log::info('✅ Timesheet created', ['id' => $timesheet->id]);
            
            $timesheet->load(['technician', 'project', 'task', 'location']);

            $validation = $this->validationService->summarize($timesheet, $request->user());
            
            return response()->json([
                'data' => $timesheet,
                'validation' => $validation->toArray(),
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
            \Log::error('❌ Failed to create timesheet', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
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
        $validation = $this->validationService->summarize($timesheet, $request->user());
        
        return response()->json([
            'data' => $timesheet,
            'validation' => $validation->toArray(),
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
        // Policy handles all authorization logic (ownership, status, project membership)
        $this->authorize('update', $timesheet);

        try {
            $validated = $request->validate([
                'project_id' => 'sometimes|exists:projects,id',
                'task_id' => 'sometimes|required|exists:tasks,id',
                'location_id' => 'sometimes|required|exists:locations,id',
                'date' => 'sometimes|date',
                'start_time' => 'nullable|date_format:H:i',
                'end_time' => 'nullable|date_format:H:i',
                'hours_worked' => 'numeric|min:0.25|max:24',
                'description' => 'nullable|string',
                'status' => ['nullable', 'string', Rule::in(['draft', 'submitted', 'rejected'])]
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
            $validation = $this->validationService->summarize($timesheet, $request->user());
            
            \Log::info('Timesheet updated successfully', ['timesheet_id' => $timesheet->id]);
            
            return response()->json([
                'data' => $timesheet,
                'validation' => $validation->toArray(),
                'permissions' => [
                    'can_view' => $request->user()->can('view', $timesheet),
                    'can_edit' => $request->user()->can('update', $timesheet),
                    'can_delete' => $request->user()->can('delete', $timesheet),
                    'can_approve' => $request->user()->can('approve', $timesheet),
                    'can_reject' => $request->user()->can('reject', $timesheet),
                ]
            ]);
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
        \Log::info('DELETE request received', [
            'timesheet_id' => $timesheet->id,
            'user_id' => $request->user()->id,
            'user_email' => $request->user()->email,
            'timesheet_status' => $timesheet->status,
            'technician_id' => $timesheet->technician_id,
            'technician_user_id' => $timesheet->technician->user_id ?? null,
        ]);

        try {
            // Policy handles all authorization logic (ownership, status, project membership)
            $this->authorize('delete', $timesheet);
            
            \Log::info('DELETE authorized, executing delete', ['timesheet_id' => $timesheet->id]);
            
            $timesheet->delete();
            return response()->json(['message' => 'Timesheet deleted successfully']);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            \Log::warning('DELETE denied by policy', [
                'timesheet_id' => $timesheet->id,
                'user_id' => $request->user()->id,
                'reason' => $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Você não tem permissão para deletar este timesheet.'
            ], 403);
        }
    }

    /**
     * Get pending timesheets for approval (managers only)
     */
    public function pending(Request $request): JsonResponse
    {
        $query = Timesheet::with(['technician', 'project', 'task', 'location'])
            ->where('status', 'submitted');

        if ($request->user()->isProjectManager()) {
            $managedProjectIds = $request->user()->getManagedProjectIds();

            if (!empty($managedProjectIds)) {
                $query->whereIn('project_id', $managedProjectIds);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $timesheets = $query->orderBy('date', 'desc')->get();
        
        return response()->json($timesheets);
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
        $this->authorize('close', $timesheet);

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
        $this->authorize('reopen', $timesheet);

        if (!$timesheet->canBeReopened()) {
            return response()->json(['error' => 'Only closed timesheets can be reopened'], 400);
        }

        $timesheet->reopen();
        $timesheet->load(['technician', 'project', 'task', 'location']);
        
        return response()->json($timesheet);
    }

    /**
     * Manager view with validation and AI insights.
     */
    public function managerView(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'closed', 'all', 'pending'])],
            'technician_ids' => 'nullable|array',
            'technician_ids.*' => 'integer|exists:technicians,id',
        ]);

        $user = $request->user();

        $dateFrom = $request->input('date_from')
            ? Carbon::parse($request->input('date_from'))
            : Carbon::now()->subDays(6);

        $dateTo = $request->input('date_to')
            ? Carbon::parse($request->input('date_to'))
            : Carbon::now();

        $query = Timesheet::with(['technician', 'project', 'task', 'location'])
            ->whereBetween('date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        $status = $request->input('status', 'submitted');
        if ($status && $status !== 'all') {
            if ($status === 'pending') {
                $query->where('status', 'submitted');
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->filled('technician_ids')) {
            $query->whereIn('technician_id', $request->input('technician_ids'));
        }

        if (!$user->hasRole('Admin')) {
            $managedProjectIds = $user->getManagedProjectIds();
            if (empty($managedProjectIds)) {
                return response()->json([
                    'data' => [],
                    'summary' => [
                        'total' => 0,
                        'flagged_count' => 0,
                        'over_cap_count' => 0,
                        'overlap_count' => 0,
                        'pending_count' => 0,
                        'average_ai_score' => null,
                    ]
                ]);
            }
            $query->whereIn('project_id', $managedProjectIds);
        }

        $timesheets = $query->orderBy('date')->get();

        $rows = $timesheets->map(function (Timesheet $timesheet) use ($user) {
            $validation = $this->validationService->summarize($timesheet, $user);
            $snapshot = $validation->snapshot;
            $date = $timesheet->date ? Carbon::parse($timesheet->date) : null;

            // Get technician's project roles
            $technicianProjectRole = null;
            $technicianExpenseRole = null;
            
            if ($timesheet->technician && $timesheet->technician->user && $timesheet->project) {
                $membership = $timesheet->project->memberRecords()
                    ->where('user_id', $timesheet->technician->user_id)
                    ->first();
                    
                if ($membership) {
                    $technicianProjectRole = $membership->project_role;
                    $technicianExpenseRole = $membership->expense_role;
                }
            }

            // Section 14.1 - Get travel data for this technician/date/project
            $travelsSummary = null;
            if ($timesheet->technician_id && $date) {
                $travels = \App\Models\TravelSegment::where('technician_id', $timesheet->technician_id)
                    ->where('travel_date', $date->toDateString())
                    ->where('project_id', $timesheet->project_id)
                    ->get();
                
                if ($travels->isNotEmpty()) {
                    $totalDurationMinutes = $travels->sum('duration_minutes') ?? 0;
                    $travelsSummary = [
                        'count' => $travels->count(),
                        'duration_minutes' => $totalDurationMinutes,
                        'duration_formatted' => $this->formatDuration($totalDurationMinutes),
                        'segment_ids' => $travels->pluck('id')->toArray(),
                    ];
                }
            }

            // Section 14.3 - Consistency flags (basic checks)
            $flags = [];
            $hoursWorked = (float) $timesheet->hours_worked;
            
            // Check: Travel segments but zero timesheet hours
            if ($travelsSummary && $travelsSummary['count'] > 0 && $hoursWorked == 0) {
                $flags[] = 'travels_without_work';
            }
            
            // Check: Travel duration very high compared to work hours (>2x)
            if ($travelsSummary && $hoursWorked > 0) {
                $travelHours = $travelsSummary['duration_minutes'] / 60;
                if ($travelHours > ($hoursWorked * 2)) {
                    $flags[] = 'excessive_travel_time';
                }
            }
            
            // Check: Expenses without timesheet hours (query expenses for this tech/date/project)
            $expensesCount = \App\Models\Expense::where('technician_id', $timesheet->technician_id)
                ->where('date', $date->toDateString())
                ->where('project_id', $timesheet->project_id)
                ->count();
            
            if ($expensesCount > 0 && $hoursWorked == 0) {
                $flags[] = 'expenses_without_work';
            }

            return [
                'id' => $timesheet->id,
                'date' => $date?->toDateString(),
                'week' => $date?->isoWeek(),
                'start_time' => $timesheet->start_time ? Carbon::parse($timesheet->start_time)->format('H:i') : null,
                'end_time' => $timesheet->end_time ? Carbon::parse($timesheet->end_time)->format('H:i') : null,
                'hours_worked' => (float) $timesheet->hours_worked,
                'status' => $timesheet->status,
                'description' => $timesheet->description,
                'project' => $timesheet->project ? [
                    'id' => $timesheet->project->id,
                    'name' => $timesheet->project->name,
                ] : null,
                'task' => $timesheet->task ? [
                    'id' => $timesheet->task->id,
                    'name' => $timesheet->task->name,
                ] : null,
                'location' => $timesheet->location ? [
                    'id' => $timesheet->location->id,
                    'name' => $timesheet->location->name,
                    'city' => $timesheet->location->city,
                    'country' => $timesheet->location->country,
                ] : null,
                'technician' => $timesheet->technician ? [
                    'id' => $timesheet->technician->id,
                    'name' => $timesheet->technician->name,
                    'email' => $timesheet->technician->email,
                ] : null,
                'technician_project_role' => $technicianProjectRole,
                'technician_expense_role' => $technicianExpenseRole,
                'travels' => $travelsSummary, // NEW: Travel data integration
                'consistency_flags' => $flags, // NEW: Section 14.3 - Consistency flags
                'ai_flagged' => $snapshot->aiFlagged,
                'ai_score' => $snapshot->aiScore,
                'ai_feedback' => $snapshot->aiFeedback,
                'validation' => $validation->toArray(),
            ];
        })->values();

        $avgScore = $rows->avg('ai_score');

        $summary = [
            'total' => $rows->count(),
            'flagged_count' => $rows->where('ai_flagged', true)->count(),
            'over_cap_count' => $rows->filter(function ($row) {
                return ($row['validation']['snapshot']['daily_total_hours'] ?? 0) > 12;
            })->count(),
            'overlap_count' => $rows->filter(function ($row) {
                return ($row['validation']['snapshot']['overlap_risk'] ?? 'ok') === 'block';
            })->count(),
            'pending_count' => $rows->where('status', 'submitted')->count(),
            'average_ai_score' => $avgScore !== null ? round($avgScore, 2) : null,
        ];

        return response()->json([
            'data' => $rows,
            'summary' => $summary,
        ]);
    }

    /**
     * Return validation snapshot for a timesheet.
     */
    public function validation(Request $request, Timesheet $timesheet): JsonResponse
    {
        $this->authorize('view', $timesheet);

        $timesheet->load(['technician', 'project', 'task', 'location']);
        $result = $this->validationService->summarize($timesheet, $request->user());

        return response()->json($result->toArray());
    }

    /**
     * Return authenticated user's projects with membership roles.
     */
    public function getUserProjects(Request $request): JsonResponse
    {
        $user = $request->user();

        $projects = $user->projects()
            ->with([
                'tasks:id,project_id,name',
                'memberRecords.user:id,name,email'
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($project) {
                $project->user_project_role = $project->pivot->project_role;
                $project->user_expense_role = $project->pivot->expense_role;
                unset($project->pivot);
                return $project;
            });

        return response()->json($projects);
    }

    /**
     * Submit a draft or rejected timesheet for review.
     */
    public function submit(Timesheet $timesheet): JsonResponse
    {
        $this->authorize('submit', $timesheet);

        if (!$timesheet->canBeSubmitted()) {
            return response()->json([
                'error' => 'Only draft or rejected timesheets can be submitted.'
            ], 422);
        }

        $timesheet->submit();
        $timesheet->load(['technician', 'project', 'task', 'location']);

        return response()->json($timesheet);
    }

    /**
     * Get count of pending approvals (timesheets + expenses).
     * Lightweight endpoint for badge counts - only counts entries the user can actually approve.
     * Excludes own entries to prevent self-approval.
     */
    public function pendingCounts(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if user has permission to approve timesheets OR expenses
        if (!$user->hasPermissionTo('approve-timesheets') && !$user->hasPermissionTo('approve-expenses')) {
            return response()->json([
                'error' => 'Unauthorized - requires approval permissions'
            ], 403);
        }

        // Admins see all pending approvals
        if ($user->hasRole('Admin')) {
            $timesheetsCount = Timesheet::where('status', 'submitted')->count();
            $expensesCount = \App\Models\Expense::where('status', 'submitted')->count();
            
            return response()->json([
                'timesheets' => $timesheetsCount,
                'expenses' => $expensesCount,
                'total' => $timesheetsCount + $expensesCount
            ]);
        }

        // Managers: only count entries from projects they manage, excluding their own
        $managedProjectIds = $user->getManagedProjectIds();

        if (empty($managedProjectIds)) {
            return response()->json([
                'timesheets' => 0,
                'expenses' => 0,
                'total' => 0
            ]);
        }

        // Count submitted timesheets from managed projects (excluding own entries)
        $timesheetsCount = Timesheet::where('status', 'submitted')
            ->whereIn('project_id', $managedProjectIds)
            ->whereHas('technician', function ($query) use ($user) {
                $query->where('user_id', '!=', $user->id);
            })
            ->count();

        // Count submitted expenses from managed projects (excluding own entries)
        $expensesCount = \App\Models\Expense::where('status', 'submitted')
            ->whereIn('project_id', $managedProjectIds)
            ->whereHas('technician', function ($query) use ($user) {
                $query->where('user_id', '!=', $user->id);
            })
            ->count();

        return response()->json([
            'timesheets' => $timesheetsCount,
            'expenses' => $expensesCount,
            'total' => $timesheetsCount + $expensesCount
        ]);
    }

    /**
     * Format duration from minutes to human-readable string (e.g., "7h 30m").
     * Section 14.1 - Helper method for travel duration formatting.
     */
    private function formatDuration(int $minutes): string
    {
        if ($minutes === 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($remainingMinutes > 0) {
            $parts[] = $remainingMinutes . 'm';
        }

        return implode(' ', $parts);
    }
}
