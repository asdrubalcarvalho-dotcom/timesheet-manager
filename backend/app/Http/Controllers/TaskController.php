<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\Project;
use App\Http\Controllers\Concerns\HandlesConstraintExceptions;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;

class TaskController extends Controller
{
    use HandlesConstraintExceptions;
    /**
     * Display a listing of tasks.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Task::with(['project', 'locations']);

        // Filter by project if provided
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        $tasks = $query->orderBy('name')->get();

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }

    /**
     * Store a newly created task.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'task_type' => 'nullable|in:retrofit,inspection,commissioning,maintenance,installation,testing,documentation,training',
            'estimated_hours' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'progress' => 'nullable|integer|min:0|max:100',
            'dependencies' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        if (isset($validated['dependencies'])) {
            $validated['dependencies'] = array_values($validated['dependencies']);
        }

        $task = Task::create($validated);
        $task->load(['project', 'locations']);

        return response()->json([
            'success' => true,
            'message' => 'Task created successfully',
            'data' => $task
        ], 201);
    }

    /**
     * Display the specified task.
     */
    public function show(Task $task): JsonResponse
    {
        $task->load(['project', 'timesheets', 'locations']);

        return response()->json([
            'success' => true,
            'data' => $task
        ]);
    }

    /**
     * Update the specified task.
     */
    public function update(Request $request, Task $task): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'project_id' => 'required|exists:projects,id',
            'task_type' => 'nullable|in:retrofit,inspection,commissioning,maintenance,installation,testing,documentation,training',
            'estimated_hours' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'progress' => 'nullable|integer|min:0|max:100',
            'dependencies' => 'nullable|array',
            'is_active' => 'boolean'
        ]);

        if (isset($validated['dependencies'])) {
            $validated['dependencies'] = array_values($validated['dependencies']);
        }

        $task->update($validated);
        $task->load(['project', 'locations']);

        return response()->json([
            'success' => true,
            'message' => 'Task updated successfully',
            'data' => $task
        ]);
    }

    /**
     * Remove the specified task.
     */
    public function destroy(Task $task): JsonResponse
    {
        // Check if task has associated timesheets
        if ($task->timesheets()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete task with associated timesheets'
            ], 422);
        }

        try {
            $task->delete();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully'
            ]);
        } catch (QueryException $e) {
            if ($this->isForeignKeyConstraint($e)) {
                return $this->constraintConflictResponse(
                    'This task cannot be deleted because it is referenced by related records (timesheets or planning links).'
                );
            }

            throw $e;
        }
    }

    /**
     * Get tasks by project.
     */
    public function byProject(Project $project): JsonResponse
    {
        $tasks = $project->tasks()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $tasks
        ]);
    }
}
