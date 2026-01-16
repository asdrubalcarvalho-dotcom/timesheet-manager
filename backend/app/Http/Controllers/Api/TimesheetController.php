<?php
/* 
IMPORTANT — READ FIRST

Before modifying, creating, or refactoring ANY list endpoint
(e.g. index(), search(), by-date, summary, picker, etc.):

1. You MUST read and follow ACCESS_RULES.md.
2. You MUST validate the endpoint against ALL list rules:
   - Technician existence
   - Project membership scoping
   - Canonical project manager detection
   - Manager segregation (managers must not see other managers)
   - List query must be >= Policy::view rules
   - System roles must NOT be used for data scoping

3. If the current behavior violates ANY rule:
   - Explicitly state: “BUG CONFIRMED”
   - Explain which rule is violated and where (file + lines)
   - DO NOT change code unless explicitly asked

4. If behavior is compliant:
   - Explicitly state: “ACCESS RULES COMPLIANT”

5. Never invent alternative access models.
6. When in doubt, return LESS data, not more.

Failure to follow ACCESS_RULES.md is considered a regression.

*/
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Technician;
use App\Models\User;
use App\Models\Timesheet;
use App\Services\Compliance\OvertimeCalculator;
use App\Services\Compliance\WorkweekCalculator;
use App\Tenancy\TenantContext;
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

        $user = $request->user();
        $isOwnerGlobalView = $user->hasRole('Owner');

        // Optional subject context (used to scope projects when viewing another technician)
        $subjectTechnician = $request->filled('technician_id')
            ? Technician::find($request->technician_id)
            : null;

        if (!$subjectTechnician && $request->filled('user_id')) {
            $subjectTechnician = Technician::where('user_id', $request->user_id)->first();
        }

        $subjectUser = $subjectTechnician?->user;
        if (!$subjectUser && $request->filled('user_id')) {
            $subjectUser = User::find($request->user_id);
        }

        $subjectManagedProjectIds = $subjectUser ? $subjectUser->getManagedProjectIds() : [];
        $subjectVisibleProjectIds = $subjectUser
            ? array_values(array_unique(array_merge(
                $subjectUser->projects()->pluck('projects.id')->toArray(),
                $subjectManagedProjectIds
            )))
            : null;

        $query = Timesheet::with(['technician', 'project', 'task', 'location']);

        if ($isOwnerGlobalView) {
            if ($subjectVisibleProjectIds !== null) {
                $query->whereIn('project_id', $subjectVisibleProjectIds);
            }
        } else {
            $technician = $user->technician
                ?? Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            // ACCESS_RULES.md — Technician requirement (no Technician => empty list)
            if (!$technician) {
                $query->whereRaw('1 = 0');
            } else {
                // ACCESS_RULES.md — Canonical project visibility (member OR canonical manager)
                $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
                $managedProjectIds = $user->getManagedProjectIds();
                $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

                if ($subjectVisibleProjectIds !== null) {
                    $visibleProjectIds = array_values(array_intersect($visibleProjectIds, $subjectVisibleProjectIds));
                }

                if (empty($visibleProjectIds)) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('project_id', $visibleProjectIds);
                }
            }
        }

        // Filter by technician (narrowing only; base query remains ACCESS_RULES-scoped)
        if ($request->has('technician_id')) {
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
        $user = $request->user();
        $project = Project::with('memberRecords')->findOrFail($validated['project_id']);

        if (!$project->isUserMember($user)) {
            return response()->json(['error' => 'You are not assigned to this project.'], 403);
        }

        $authTechnician = Technician::where('user_id', $user->id)->first()
            ?? Technician::where('email', $user->email)->first();

        if (!$authTechnician) {
            return response()->json(['error' => 'Technician profile not found'], 404);
        }

        $requestedTechnicianId = (int) ($validated['technician_id'] ?? 0);
        $isProjectManager = $project->isUserProjectManager($user);

        if ($requestedTechnicianId === 0) {
            $validated['technician_id'] = $authTechnician->id;
        } elseif ($requestedTechnicianId !== (int) $authTechnician->id) {
            if (!$isProjectManager) {
                return response()->json([
                    'error' => 'Only project managers can create records for other technicians.'
                ], 403);
            }

            $technicianFromRequest = Technician::find($requestedTechnicianId);
            if (!$technicianFromRequest) {
                return response()->json(['error' => 'Worker not found'], 404);
            }

            if ($technicianFromRequest->user && !$project->memberRecords()->where('user_id', $technicianFromRequest->user->id)->exists()) {
                return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
            }

            $validated['technician_id'] = $technicianFromRequest->id;
        }

        if (!isset($validated['status'])) {
            $validated['status'] = 'draft';
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

            $response = [
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

            ];

            return response()->json($response, 201);
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
                'technician_id' => 'sometimes|integer|exists:technicians,id',
                'task_id' => 'sometimes|required|exists:tasks,id',
                'location_id' => 'sometimes|required|exists:locations,id',
                'date' => 'sometimes|date',
                'start_time' => 'nullable|date_format:H:i',
                'end_time' => 'nullable|date_format:H:i',
                'hours_worked' => 'numeric|min:0.25|max:24',
                'description' => 'nullable|string',
                'status' => ['nullable', 'string', Rule::in(['draft', 'submitted', 'rejected'])]
            ]);

            $user = $request->user();
            $projectId = $validated['project_id'] ?? $timesheet->project_id;
            $project = Project::with('memberRecords')->findOrFail($projectId);

            if (!$project->isUserMember($user)) {
                return response()->json(['error' => 'You are not assigned to this project.'], 403);
            }

            $authTechnician = Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            if (!$authTechnician) {
                return response()->json(['error' => 'Technician profile not found'], 404);
            }

            $requestedTechnicianId = (int) ($validated['technician_id'] ?? $timesheet->technician_id);
            $isProjectManager = $project->isUserProjectManager($user);

            if ($requestedTechnicianId !== (int) $authTechnician->id && !$isProjectManager) {
                return response()->json([
                    'error' => 'Only project managers can create records for other technicians.'
                ], 403);
            }

            if (array_key_exists('technician_id', $validated) && $requestedTechnicianId !== (int) $authTechnician->id) {
                $targetTechnician = Technician::find($requestedTechnicianId);
                if (!$targetTechnician) {
                    return response()->json(['error' => 'Worker not found'], 404);
                }

                if ($targetTechnician->user && !$project->memberRecords()->where('user_id', $targetTechnician->user->id)->exists()) {
                    return response()->json(['error' => 'This worker is not a member of the selected project.'], 422);
                }
            } else {
                // Preserve existing technician when not changing
                $validated['technician_id'] = $timesheet->technician_id;
            }

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
            
            $response = [
                'data' => $timesheet,
                'validation' => $validation->toArray(),
                'permissions' => [
                    'can_view' => $request->user()->can('view', $timesheet),
                    'can_edit' => $request->user()->can('update', $timesheet),
                    'can_delete' => $request->user()->can('delete', $timesheet),
                    'can_approve' => $request->user()->can('approve', $timesheet),
                    'can_reject' => $request->user()->can('reject', $timesheet),
                ]
            ];

            return response()->json($response);
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
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $timesheet->reject($validated['reason']);
        $timesheet->load(['technician', 'project', 'task', 'location']);

        return response()->json($timesheet);
    }

    /**
     * GET /api/timesheets/manager-view
     * Manager approvals view: pending timesheets that the user can approve.
     */
    public function managerView(Request $request): JsonResponse
    {
        $user = $request->user();

        $status = $request->input('status', 'submitted');

        $query = Timesheet::with([
            'technician',
            'project.memberRecords',
            'task',
            'location',
        ])->where('status', $status);

        if ($request->filled('date_from')) {
            $dateFrom = Carbon::parse($request->input('date_from'));
            $query->where('date', '>=', $dateFrom->toDateString());
        }

        if ($request->filled('date_to')) {
            $dateTo = Carbon::parse($request->input('date_to'));
            $query->where('date', '<=', $dateTo->toDateString());
        }

        if ($request->filled('technician_ids')) {
            $query->whereIn('technician_id', $request->input('technician_ids'));
        }

        // Canonical manager scope: only managed projects; never system-role based.
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

        // Prevent self-approval lists (policy will also guard actions)
        $query->whereHas('technician', function ($q) use ($user) {
            $q->where('user_id', '!=', $user->id);
        });

        $timesheets = $query->orderBy('date')->get();

        // Ensure we never return rows that the policy would forbid approving.
        $timesheets = $timesheets->filter(function (Timesheet $timesheet) use ($user) {
            return $user->can('approve', $timesheet);
        })->values();

        $rows = $timesheets->map(function (Timesheet $timesheet) use ($user) {
            $validation = $this->validationService->summarize($timesheet, $user);
            $snapshot = $validation->snapshot;
            $date = $timesheet->date ? Carbon::parse($timesheet->date) : null;

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

            $flags = [];
            $hoursWorked = (float) $timesheet->hours_worked;

            if ($travelsSummary && $travelsSummary['count'] > 0 && $hoursWorked == 0) {
                $flags[] = 'travels_without_work';
            }

            if ($travelsSummary && $hoursWorked > 0) {
                $travelHours = $travelsSummary['duration_minutes'] / 60;
                if ($travelHours > ($hoursWorked * 2)) {
                    $flags[] = 'excessive_travel_time';
                }
            }

            $expensesCount = 0;
            if ($timesheet->technician_id && $date) {
                $expensesCount = \App\Models\Expense::where('technician_id', $timesheet->technician_id)
                    ->where('date', $date->toDateString())
                    ->where('project_id', $timesheet->project_id)
                    ->count();
            }

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
                'travels' => $travelsSummary,
                'consistency_flags' => $flags,
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
     * GET /api/timesheets/summary
     * Weekly summary for the requested date (tenant-configured workweek).
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        /** @var \App\Models\Tenant $tenant */
        $tenant = app(\App\Models\Tenant::class);
        $context = app(TenantContext::class);

        $user = $request->user();
        $isOwner = $user->hasRole('Owner');

        if (!$isOwner) {
            $technician = $user->technician
                ?? Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            if (!$technician) {
                return response()->json([
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'overtime_rate' => 1.5,
                    'workweek_start' => null,
                ]);
            }
        }

        $date = $request->filled('date')
            ? Carbon::parse((string) $request->input('date'), $context->timezone)
            : now($context->timezone);

        $period = app(WorkweekCalculator::class)->periodForDate($tenant, $context, $date);

        $query = Timesheet::query();

        if (!$isOwner) {
            $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
            $managedProjectIds = $user->getManagedProjectIds();
            $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

            if (empty($visibleProjectIds)) {
                return response()->json([
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'overtime_rate' => 1.5,
                    'workweek_start' => $period['start']->toDateString(),
                ]);
            }

            $query->whereIn('project_id', $visibleProjectIds);
        }

        $query
            ->where('date', '>=', $period['start']->toDateString())
            ->where('date', '<=', $period['end']->toDateString());

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', (int) $request->input('technician_id'));
        }

        $weekHours = (float) $query->sum('hours_worked');

        $overtime = app(OvertimeCalculator::class)->calculateForTenant($tenant, $weekHours);

        return response()->json([
            'regular_hours' => round($overtime['regular_hours'], 2),
            'overtime_hours' => round($overtime['overtime_hours'], 2),
            'overtime_rate' => $overtime['overtime_rate'],
            'workweek_start' => $period['start']->toDateString(),
        ]);
    }

    /**
     * GET /api/timesheets/week
     * Timesheets for the tenant-configured workweek containing the requested date.
     */
    public function week(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Timesheet::class);

        /** @var \App\Models\Tenant $tenant */
        $tenant = app(\App\Models\Tenant::class);
        $context = app(TenantContext::class);

        $user = $request->user();
        $isOwner = $user->hasRole('Owner');

        if (!$isOwner) {
            $technician = $user->technician
                ?? Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            if (!$technician) {
                return response()->json([
                    'data' => [],
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'overtime_rate' => 1.5,
                    'workweek_start' => null,
                ]);
            }
        }

        $date = $request->filled('date')
            ? Carbon::parse((string) $request->input('date'), $context->timezone)
            : now($context->timezone);

        $period = app(WorkweekCalculator::class)->periodForDate($tenant, $context, $date);

        $query = Timesheet::with(['technician', 'project', 'task', 'location']);

        if (!$isOwner) {
            $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
            $managedProjectIds = $user->getManagedProjectIds();
            $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

            if (empty($visibleProjectIds)) {
                return response()->json([
                    'data' => [],
                    'regular_hours' => 0,
                    'overtime_hours' => 0,
                    'overtime_rate' => 1.5,
                    'workweek_start' => $period['start']->toDateString(),
                ]);
            }

            $query->whereIn('project_id', $visibleProjectIds);
        }

        $query
            ->where('date', '>=', $period['start']->toDateString())
            ->where('date', '<=', $period['end']->toDateString());

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', (int) $request->input('technician_id'));
        }

        $timesheets = $query->orderBy('date')->get();
        $weekHours = (float) $timesheets->sum('hours_worked');

        $overtime = app(OvertimeCalculator::class)->calculateForTenant($tenant, $weekHours);

        return response()->json([
            'data' => $timesheets,
            'regular_hours' => round($overtime['regular_hours'], 2),
            'overtime_hours' => round($overtime['overtime_hours'], 2),
            'overtime_rate' => $overtime['overtime_rate'],
            'workweek_start' => $period['start']->toDateString(),
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
        $isGlobalView = $user->hasRole(['Owner', 'Admin']);

        // Optional subject context for dropdown scoping
        $subjectTechnician = $request->filled('technician_id')
            ? Technician::find($request->technician_id)
            : null;

        if (!$subjectTechnician && $request->filled('user_id')) {
            $subjectTechnician = Technician::where('user_id', $request->user_id)->first();
        }

        $subjectUser = $subjectTechnician?->user;
        if (!$subjectUser && $request->filled('user_id')) {
            $subjectUser = User::find($request->user_id);
        }

        $subjectManagedProjectIds = $subjectUser ? $subjectUser->getManagedProjectIds() : [];
        $subjectVisibleProjectIds = $subjectUser
            ? array_values(array_unique(array_merge(
                $subjectUser->projects()->pluck('projects.id')->toArray(),
                $subjectManagedProjectIds
            )))
            : null;

        $technician = $user->technician
            ?? Technician::where('user_id', $user->id)->first()
            ?? Technician::where('email', $user->email)->first();

        // ACCESS_RULES.md — Technician requirement (no Technician => empty list)
        if (!$technician && !$isGlobalView) {
            return response()->json([]);
        }

        // ACCESS_RULES.md — Canonical project visibility (member OR canonical manager)
        $memberProjectIds = $technician ? $user->projects()->pluck('projects.id')->toArray() : [];
        $managedProjectIds = $technician ? $user->getManagedProjectIds() : [];
        $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

        if ($subjectVisibleProjectIds !== null) {
            $visibleProjectIds = $isGlobalView
                ? $subjectVisibleProjectIds
                : array_values(array_intersect($visibleProjectIds, $subjectVisibleProjectIds));
            $managedProjectIds = $isGlobalView
                ? $subjectManagedProjectIds
                : array_values(array_intersect($managedProjectIds, $subjectManagedProjectIds));
        }

        if (empty($visibleProjectIds)) {
            return response()->json([]);
        }

        $roleUser = $subjectUser ?? $user;

        $projects = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->with([
                'tasks:id,project_id,name',
                'memberRecords' => function ($query) use ($roleUser) {
                    $query->where('user_id', $roleUser->id);
                },
                'memberRecords.user:id,name,email'
            ])
            ->orderBy('name')
            ->get()
            ->map(function ($project) use ($roleUser, $managedProjectIds) {
                $memberRecord = $project->memberRecords->firstWhere('user_id', $roleUser->id);

                $project->user_project_role = $memberRecord?->project_role;
                $project->user_expense_role = $memberRecord?->expense_role;

                if (!$memberRecord && in_array($project->id, $managedProjectIds, true)) {
                    $project->user_project_role = 'manager';
                    $project->user_expense_role = $project->user_expense_role ?? 'manager';
                }

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
