<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\HandlesConstraintExceptions;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    use HandlesConstraintExceptions;

    private const PROJECT_ROLE_VALUES = ['member', 'manager'];
    private const EXPENSE_ROLE_VALUES = ['member', 'manager'];
    private const FINANCE_ROLE_VALUES = ['none', 'member', 'manager'];

    /**
     * Normalize role field names from request.
     *
     * Supports:
     * - Admin UI: project_role/expense_role/finance_role
     * - Planning minimal payload: only user_id
     * - Alias: timesheet_role (maps to project_role)
     */
    private function extractRoleUpdates(Request $request): array
    {
        // Accept alias `timesheet_role` as `project_role`.
        $projectRole = $request->input('project_role', $request->input('timesheet_role'));

        $updates = [];

        if ($projectRole !== null) {
            $updates['project_role'] = $projectRole;
        }

        if ($request->has('expense_role')) {
            $updates['expense_role'] = $request->input('expense_role');
        }

        if ($request->has('finance_role')) {
            $updates['finance_role'] = $request->input('finance_role');
        }

        return $updates;
    }

    private function formatMember(ProjectMember $member): array
    {
        $member->loadMissing('user.roles');

        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'project_role' => $member->project_role,
            'expense_role' => $member->expense_role,
            'finance_role' => $member->finance_role,
            'user' => $member->user ? [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'roles' => $member->user->roles->pluck('name'),
            ] : null,
        ];
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $with = ['timesheets', 'expenses', 'manager'];

        // Used by Planning (Users view) to build a user -> project hierarchy
        if ($request->boolean('include_members')) {
            $with[] = 'members';
        }

        $query = Project::with($with);
        
        $user = $request->user();
        
        // Filter to only MANAGED projects (for Travel Segments - user must be manager)
        if ($request->boolean('managed_only')) {
            \Log::info('Filtering MANAGED projects for user: ' . $user->id);
            $managedProjectIds = $user->getManagedProjectIds();
            $query->whereIn('id', $managedProjectIds);
        }
        // Filter to only user's projects (manager OR member - for general use)
        elseif ($request->boolean('my_projects')) {
            \Log::info('Filtering projects for user: ' . $user->id);

            // ACCESS_RULES.md — Technician requirement (no Technician => empty list)
            $technician = $user->technician
                ?? Technician::where('user_id', $user->id)->first()
                ?? Technician::where('email', $user->email)->first();

            if (!$technician) {
                return response()->json([
                    'data' => [],
                    'user_permissions' => [
                        'can_manage_projects' => auth()->user()->can('manage-projects'),
                        'is_manager' => auth()->user()->isProjectManager(),
                        'is_admin' => auth()->user()->hasRole('Admin'),
                    ]
                ]);
            }

            // ACCESS_RULES.md — Canonical project visibility (member OR canonical manager)
            $memberProjectIds = $user->projects()->pluck('projects.id')->toArray();
            $managedProjectIds = $user->getManagedProjectIds();
            $visibleProjectIds = array_values(array_unique(array_merge($memberProjectIds, $managedProjectIds)));

            if (empty($visibleProjectIds)) {
                return response()->json([
                    'data' => [],
                    'user_permissions' => [
                        'can_manage_projects' => auth()->user()->can('manage-projects'),
                        'is_manager' => auth()->user()->isProjectManager(),
                        'is_admin' => auth()->user()->hasRole('Admin'),
                    ]
                ]);
            }

            $query->whereIn('id', $visibleProjectIds);
        }
        
        $projects = $query->orderBy('created_at', 'desc')->get();
        \Log::info('Returning projects count: ' . $projects->count());
            
        return response()->json([
            'data' => $projects,
            'user_permissions' => [
                'can_manage_projects' => auth()->user()->can('manage-projects'),
                'is_manager' => auth()->user()->isProjectManager(), // Based on project relationships
                'is_admin' => auth()->user()->hasRole('Admin'),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['nullable', 'string', Rule::in(['active', 'completed', 'on_hold'])],
            'manager_id' => 'nullable|exists:users,id'
        ]);

        // Set default status if not provided
        if (!isset($validated['status'])) {
            $validated['status'] = 'active';
        }

        $project = Project::create($validated);
        return response()->json($project, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Project $project): JsonResponse
    {
        $project->load(['timesheets.technician', 'expenses.technician', 'manager']);
        return response()->json($project);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => [Rule::in(['active', 'completed', 'on_hold'])],
            'manager_id' => 'nullable|exists:users,id'
        ]);

        $project->update($validated);
        return response()->json($project);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Project $project): JsonResponse
    {
        try {
            $project->delete();
            return response()->json(['message' => 'Project deleted successfully']);
        } catch (QueryException $e) {
            if ($this->isForeignKeyConstraint($e)) {
                return $this->constraintConflictResponse(
                    'This project cannot be deleted because it has related records (tasks, members, timesheets, expenses or travels).'
                );
            }

            throw $e;
        }
    }

    /**
     * Get all members of a project with their roles
     */
    public function getMembers(Project $project): JsonResponse
    {
        $members = $project->memberRecords()
            ->with(['user.roles'])
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'user_id' => $member->user_id,
                    'project_role' => $member->project_role,
                    'expense_role' => $member->expense_role,
                    'finance_role' => $member->finance_role,
                    'user' => $member->user ? [
                        'id' => $member->user->id,
                        'name' => $member->user->name,
                        'email' => $member->user->email,
                        'roles' => $member->user->roles->pluck('name'),
                    ] : null,
                ];
            });

        return response()->json($members);
    }

    /**
     * Get project roles for a specific user
     */
    public function getUserRoles(Project $project, Request $request): JsonResponse
    {
        $user = $request->user();
        $member = $project->memberRecords()->where('user_id', $user->id)->first();

        if (!$member) {
            return response()->json([
                'project_role' => null,
                'expense_role' => null,
                'finance_role' => null,
            ]);
        }

        return response()->json([
            'project_role' => $member->project_role,
            'expense_role' => $member->expense_role,
            'finance_role' => $member->finance_role,
        ]);
    }

    /**
     * Add a member to the project
     */
    public function addMember(Request $request, Project $project): JsonResponse
    {
        // Planning minimal payload: { user_id }
        // Admin payload may include roles.
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'project_role' => ['sometimes', Rule::in(self::PROJECT_ROLE_VALUES)],
            'timesheet_role' => ['sometimes', Rule::in(self::PROJECT_ROLE_VALUES)],
            'expense_role' => ['sometimes', Rule::in(self::EXPENSE_ROLE_VALUES)],
            'finance_role' => ['sometimes', Rule::in(self::FINANCE_ROLE_VALUES)],
        ]);

        $userId = (int) $validated['user_id'];
        $roleUpdates = $this->extractRoleUpdates($request);
        $hasAnyRoleField = count($roleUpdates) > 0;

        // Prevent duplicates: if membership exists, make this idempotent.
        $existing = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        if ($existing) {
            // If roles provided, update them. If not, do nothing (idempotent).
            if ($hasAnyRoleField) {
                $existing->update($roleUpdates);
            }

            return response()->json($this->formatMember($existing), 200);
        }

        // Apply sensible defaults if roles are missing.
        $createPayload = array_merge([
            'project_id' => $project->id,
            'user_id' => $userId,
            'project_role' => 'member',
            'expense_role' => 'member',
            'finance_role' => 'none',
        ], $roleUpdates);

        $member = ProjectMember::create($createPayload);

        return response()->json($this->formatMember($member), 200);
    }

    /**
     * Update a member's roles in the project
     */
    public function updateMember(Request $request, Project $project, User $user): JsonResponse
    {
        // Update only fields present. Support alias `timesheet_role`.
        $validated = $request->validate([
            'project_role' => ['sometimes', Rule::in(self::PROJECT_ROLE_VALUES)],
            'timesheet_role' => ['sometimes', Rule::in(self::PROJECT_ROLE_VALUES)],
            'expense_role' => ['sometimes', Rule::in(self::EXPENSE_ROLE_VALUES)],
            'finance_role' => ['sometimes', Rule::in(self::FINANCE_ROLE_VALUES)],
        ]);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $roleUpdates = $this->extractRoleUpdates($request);
        if (count($roleUpdates) > 0) {
            $member->update($roleUpdates);
        }

        return response()->json($this->formatMember($member), 200);
    }

    /**
     * Remove a member from the project
     */
    public function removeMember(Project $project, User $user): JsonResponse
    {
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $member->delete();

        return response()->json([
            'message' => 'Member removed successfully',
        ], 200);
    }
}
