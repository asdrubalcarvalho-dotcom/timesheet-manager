<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $projects = Project::with(['timesheets', 'expenses', 'manager'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json([
            'data' => $projects,
            'user_permissions' => [
                'can_manage_projects' => auth()->user()->can('manage-projects'),
                'is_manager' => auth()->user()->hasRole('Manager'),
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
            'end_date' => 'nullable|date|after:start_date',
            'status' => ['string', Rule::in(['active', 'completed', 'on_hold'])],
            'manager_id' => 'nullable|exists:users,id'
        ]);

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
}
