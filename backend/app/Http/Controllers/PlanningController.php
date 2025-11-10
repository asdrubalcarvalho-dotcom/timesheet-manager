<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Project;
use App\Models\Task;
use App\Models\Resource;
use App\Models\Location;
use Illuminate\Support\Facades\Schema;

class PlanningController extends Controller
{
    // CRUD Project
    public function indexProjects()
    {
        $query = Project::query();

        $withCounts = [];
        if (Schema::hasTable('tasks')) {
            $withCounts[] = 'tasks';
        }
        if (Schema::hasTable('project_resource') && Schema::hasTable('resources')) {
            $withCounts[] = 'resources';
        }

        if (!empty($withCounts)) {
            $query->withCount($withCounts);
        }

        return $query->get();
    }
    public function showProject(Project $project)
    {
        $relations = [];
        if (Schema::hasTable('tasks')) {
            $relations[] = 'tasks';
        }
        if (Schema::hasTable('project_resource') && Schema::hasTable('resources')) {
            $relations[] = 'resources';
        }

        if (!empty($relations)) {
            $project->load($relations);
        }

        return $project;
    }
    public function storeProject(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|in:planned,active,on_hold,completed',
        ]);
        return Project::create($data);
    }
    public function updateProject(Request $request, Project $project)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'sometimes|in:planned,active,on_hold,completed',
        ]);
        $project->update($data);
        return $project;
    }
    public function destroyProject(Project $project)
    {
        $project->delete();
        return response()->json(['ok' => true]);
    }

    // CRUD Task
    public function indexTasks(Request $request)
    {
        $projectId = $request->query('project_id');
        $query = Task::query();
        if ($projectId) $query->where('project_id', $projectId);
        $with = [];
        if (Schema::hasTable('resource_task') && Schema::hasTable('resources')) {
            $with[] = 'resources';
        }
        if (Schema::hasTable('location_task') && Schema::hasTable('locations')) {
            $with[] = 'locations';
        }
        if (!empty($with)) {
            $query->with($with);
        }
        return $query->get();
    }
    public function showTask(Task $task)
    {
        $relations = [];
        if (Schema::hasTable('resource_task') && Schema::hasTable('resources')) {
            $relations[] = 'resources';
        }
        if (Schema::hasTable('location_task') && Schema::hasTable('locations')) {
            $relations[] = 'locations';
        }
        if (!empty($relations)) {
            $task->load($relations);
        }
        return $task;
    }
    public function storeTask(Request $request)
    {
        $data = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'progress' => 'integer|min:0|max:100',
            'dependencies' => 'nullable|string',
        ]);
        return Task::create($data);
    }
    public function updateTask(Request $request, Task $task)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
            'progress' => 'sometimes|integer|min:0|max:100',
            'dependencies' => 'nullable|string',
        ]);
        $task->update($data);
        return $task;
    }
    public function destroyTask(Task $task)
    {
        $task->delete();
        return response()->json(['ok' => true]);
    }

    // CRUD Resource
    public function indexResources()
    {
        return Resource::with('user')->get();
    }
    public function showResource(Resource $resource)
    {
        return $resource->load('user');
    }
    public function storeResource(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string',
            'meta' => 'nullable|array',
            'user_id' => 'nullable|exists:users,id',
        ]);
        return Resource::create($data);
    }
    public function updateResource(Request $request, Resource $resource)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'nullable|string',
            'meta' => 'nullable|array',
            'user_id' => 'nullable|exists:users,id',
        ]);
        $resource->update($data);
        return $resource;
    }
    public function destroyResource(Resource $resource)
    {
        $resource->delete();
        return response()->json(['ok' => true]);
    }

    // CRUD Location
    public function indexLocations()
    {
        return Location::all();
    }
    public function showLocation(Location $location)
    {
        return $location;
    }
    public function storeLocation(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'country' => 'nullable|string',
            'timezone' => 'nullable|string',
            'meta' => 'nullable|array',
        ]);
        return Location::create($data);
    }
    public function updateLocation(Request $request, Location $location)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'country' => 'nullable|string',
            'timezone' => 'nullable|string',
            'meta' => 'nullable|array',
        ]);
        $location->update($data);
        return $location;
    }
    public function destroyLocation(Location $location)
    {
        $location->delete();
        return response()->json(['ok' => true]);
    }
}
