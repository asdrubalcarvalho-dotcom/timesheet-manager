<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Display a listing of projects for the authenticated user.
     * Permission 'manage-projects' is checked at route level.
     */
    public function index(Request $request): JsonResponse
    {
        // Filter to only user's projects if requested (like timesheets)
        if ($request->boolean('my_projects')) {
            $user = $request->user();
            $projects = Project::with(['memberRecords.user', 'tasks', 'manager'])
                ->where(function ($q) use ($user) {
                    // Projects where user is manager
                    $q->where('manager_id', $user->id)
                      // OR projects where user is a member
                      ->orWhereHas('memberRecords', function ($memberQuery) use ($user) {
                          $memberQuery->where('user_id', $user->id);
                      });
                })
                ->get();
        } else {
            // User already has manage-projects permission (checked at route level)
            // Show all projects with their members and tasks
            $projects = Project::with(['memberRecords.user', 'tasks', 'manager'])->get();
        }

        return response()->json($projects);
    }

    /**
     * Display the specified project.
     */
    public function show(Project $project): JsonResponse
    {
        $user = Auth::user();

        // Verificar se user pode ver este projeto
        if (!$user->hasRole('Admin') && !$project->isUserMember($user)) {
            return response()->json(['message' => 'You do not have access to this project.'], 403);
        }

        $project->load(['memberRecords.user', 'tasks', 'manager', 'timesheets', 'expenses']);

        // Adicionar informação de role do user atual
        if (!$user->hasRole('Admin')) {
            $memberRecord = $project->memberRecords->where('user_id', $user->id)->first();
            $project->user_project_role = $memberRecord?->project_role;
            $project->user_expense_role = $memberRecord?->expense_role;
        }

        return response()->json($project);
    }

    /**
     * Store a newly created project (Admins/Managers with permission).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['required', Rule::in(['planned', 'active', 'completed', 'on_hold'])],
            'manager_id' => 'nullable|exists:users,id'
        ]);

        $project = Project::create($validated);
        $project->load(['memberRecords.user', 'tasks', 'manager']);

        return response()->json($project, 201);
    }

    /**
     * Update an existing project (Admins/Managers with permission).
     */
    public function update(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => ['sometimes', Rule::in(['planned', 'active', 'completed', 'on_hold'])],
            'manager_id' => 'nullable|exists:users,id'
        ]);

        $project->update($validated);
        $project->load(['memberRecords.user', 'tasks', 'manager']);

        return response()->json($project);
    }

    /**
     * Delete a project (Admins/Managers with permission).
     */
    public function destroy(Project $project): JsonResponse
    {
        $project->delete();

        return response()->json([
            'message' => 'Project deleted successfully.'
        ]);
    }

    /**
     * Get project members with their roles.
     * Permission 'manage-projects' is checked at route level.
     */
    public function getMembers(Project $project): JsonResponse
    {
        $members = $project->memberRecords()->with('user')->get();
        return response()->json($members);
    }

    /**
     * Add a member to the project.
     * Permission 'manage-projects' is checked at route level.
     */
    public function addMember(Request $request, Project $project): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'project_role' => 'required|in:member,manager,none',
            'expense_role' => 'required|in:member,manager,none',
            'finance_role' => 'required|in:member,manager,none'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Verificar se user já é member
        $existingMember = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $request->user_id)
            ->first();

        if ($existingMember) {
            // Atualizar roles existentes
            $existingMember->update([
                'project_role' => $request->project_role,
                'expense_role' => $request->expense_role,
                'finance_role' => $request->finance_role
            ]);
            $member = $existingMember;
        } else {
            // Criar novo member
            $member = ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $request->user_id,
                'project_role' => $request->project_role,
                'expense_role' => $request->expense_role,
                'finance_role' => $request->finance_role
            ]);
        }

        $member->load('user');

        return response()->json($member, 201);
    }

    /**
     * Update a member's roles in the project.
     * Permission 'manage-projects' is checked at route level.
     */
    public function updateMember(Request $request, Project $project, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'project_role' => 'required|in:member,manager,none',
            'expense_role' => 'required|in:member,manager,none',
            'finance_role' => 'required|in:member,manager,none'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $member->update([
            'project_role' => $request->project_role,
            'expense_role' => $request->expense_role,
            'finance_role' => $request->finance_role
        ]);

        $member->load('user');

        return response()->json($member);
    }

    /**
     * Remove a member from the project.
     * Permission 'manage-projects' is checked at route level.
     */
    public function removeMember(Project $project, User $user): JsonResponse
    {
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $member->delete();

        return response()->json(['message' => 'Member removed from project successfully.']);
    }

    /**
     * Get user's role information for a specific project.
     */
    public function getUserRoles(Project $project): JsonResponse
    {
        $user = Auth::user();

        if (!$project->isUserMember($user)) {
            return response()->json(['message' => 'You are not a member of this project.'], 403);
        }

        $memberRecord = $project->memberRecords()->where('user_id', $user->id)->first();

        return response()->json([
            'project_role' => $memberRecord?->project_role,
            'expense_role' => $memberRecord?->expense_role,
            'can_manage_timesheets' => $project->isUserProjectManager($user),
            'can_manage_expenses' => $project->isUserExpenseManager($user),
        ]);
    }
}
