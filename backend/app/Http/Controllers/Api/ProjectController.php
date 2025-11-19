<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Project::with(['timesheets', 'expenses', 'manager']);
        
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
            $query->where(function ($q) use ($user) {
                // Projects where user is manager
                $q->where('manager_id', $user->id)
                  // OR projects where user is a member
                  ->orWhereHas('memberRecords', function ($memberQuery) use ($user) {
                      $memberQuery->where('user_id', $user->id);
                  });
            });
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
        $project->delete();
        return response()->json(['message' => 'Project deleted successfully']);
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
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'project_role' => ['required', Rule::in(['member', 'manager'])],
            'expense_role' => ['required', Rule::in(['member', 'manager'])],
            'finance_role' => ['required', Rule::in(['none', 'member', 'manager'])],
        ]);

        // Check if user is already a member
        $existing = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $validated['user_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'User is already a member of this project'
            ], 422);
        }

        $member = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $validated['user_id'],
            'project_role' => $validated['project_role'],
            'expense_role' => $validated['expense_role'],
            'finance_role' => $validated['finance_role'],
        ]);

        $member->load('user.roles');

        return response()->json([
            'id' => $member->id,
            'user_id' => $member->user_id,
            'project_role' => $member->project_role,
            'expense_role' => $member->expense_role,
            'finance_role' => $member->finance_role,
            'user' => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'roles' => $member->user->roles->pluck('name'),
            ],
        ], 201);
    }

    /**
     * Update a member's roles in the project
     */
    public function updateMember(Request $request, Project $project, User $user): JsonResponse
    {
        $validated = $request->validate([
            'project_role' => ['required', Rule::in(['member', 'manager'])],
            'expense_role' => ['required', Rule::in(['member', 'manager'])],
            'finance_role' => ['required', Rule::in(['none', 'member', 'manager'])],
        ]);

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $member->update($validated);
        $member->load('user.roles');

        return response()->json([
            'id' => $member->id,
            'user_id' => $member->user_id,
            'project_role' => $member->project_role,
            'expense_role' => $member->expense_role,
            'finance_role' => $member->finance_role,
            'user' => [
                'id' => $member->user->id,
                'name' => $member->user->name,
                'email' => $member->user->email,
                'roles' => $member->user->roles->pluck('name'),
            ],
        ]);
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
            'message' => 'Member removed successfully'
        ]);
    }
}
