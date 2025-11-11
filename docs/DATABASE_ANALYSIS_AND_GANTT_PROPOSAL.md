# Database Analysis & Gantt Chart Implementation Proposal

## Current Database Structure Analysis

### ✅ Existing Tables & Relationships

#### Core Entities

**1. Users** (Laravel Auth + Spatie Permissions)
- Fields: `id`, `name`, `email`, `password`, `role`, `timestamps`
- Relationships:
  - **1:N** → `technicians` (via `technicians.user_id`)
  - **1:N** → `projects` as manager (via `projects.manager_id`)
  - **N:N** → `roles` (via `model_has_roles` pivot - Spatie)
  - **N:N** → `permissions` (via `model_has_permissions` pivot - Spatie)

**2. Technicians**
- Fields: `id`, `name`, `email`, `user_id`, `role`, `hourly_rate`, `is_active`, `timestamps`
- Relationships:
  - **N:1** → `users` (via `user_id`)
  - **1:N** → `timesheets`
  - **1:N** → `expenses`
  - **Missing:** `home_location_id` (proposed in Travel feature)

**3. Projects**
- Fields: `id`, `name`, `description`, `start_date`, `end_date`, `status`, `manager_id`, `timestamps`
- Relationships:
  - **N:1** → `users` as manager (via `manager_id`)
  - **1:N** → `timesheets`
  - **1:N** → `expenses`
  - **1:N** → `tasks`
  - **Missing N:N** → `technicians` (no project_members pivot table!)
  - **Missing N:N** → `locations` (no project_locations pivot table!)

**4. Tasks**
- Fields: `id`, `project_id`, `name`, `description`, `task_type`, `is_active`, `timestamps`
- Relationships:
  - **N:1** → `projects`
  - **1:N** → `timesheets`
  - **Missing:** `requires_travel`, `estimated_hours`, `start_date`, `end_date`, `assigned_technician_id`

**5. Locations**
- Fields: `id`, `name`, `address`, `latitude`, `longitude`, `description`, `is_active`, `timestamps`
- Relationships:
  - **1:N** → `timesheets`
  - **Missing N:N** → `projects` (locations available per project)

**6. Timesheets**
- Fields: `id`, `technician_id`, `project_id`, `task_id`, `location_id`, `date`, `start_time`, `end_time`, `hours_worked`, `description`, `status`, `rejection_reason`, `timestamps`
- Relationships:
  - **N:1** → `technicians`
  - **N:1** → `projects`
  - **N:1** → `tasks`
  - **N:1** → `locations`
  - Constraints: `UNIQUE(technician_id, project_id, task_id, date)`

**7. Expenses**
- Fields: `id`, `technician_id`, `project_id`, `date`, `amount`, `category`, `description`, `attachment_path`, `status`, `rejection_reason`, `timestamps`
- Relationships:
  - **N:1** → `technicians`
  - **N:1** → `projects`

---

## 🚨 Missing Critical Relationships

### 1. **Project Members (N:N)**
**Problem:** No way to assign multiple technicians to a project
**Current State:** Only manager assignment via `projects.manager_id`
**Required:** Pivot table for project team members

```php
// Missing Migration
Schema::create('project_members', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('technician_id')->constrained()->onDelete('cascade');
    $table->enum('role', ['member', 'lead', 'observer'])->default('member');
    $table->date('assigned_at')->default(DB::raw('CURRENT_DATE'));
    $table->date('removed_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
    
    $table->unique(['project_id', 'technician_id']);
    $table->index(['project_id', 'is_active']);
});
```

### 2. **Project Locations (N:N)**
**Problem:** No way to define which locations are valid for a project
**Impact:** Technicians can select any location, even irrelevant ones
**Required:** Pivot table for project-specific locations

```php
// Missing Migration
Schema::create('project_locations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('location_id')->constrained()->onDelete('cascade');
    $table->boolean('is_primary')->default(false); // Main project site
    $table->timestamps();
    
    $table->unique(['project_id', 'location_id']);
});
```

### 3. **Task Assignments (N:N)**
**Problem:** Tasks are not assigned to specific technicians
**Impact:** No way to track who should work on what
**Required:** Pivot table or direct FK

```php
// Option A: Direct assignment (1:N)
Schema::table('tasks', function (Blueprint $table) {
    $table->foreignId('assigned_technician_id')
          ->nullable()
          ->after('project_id')
          ->constrained('technicians')
          ->onDelete('set null');
});

// Option B: Multiple assignments (N:N) - Better for Gantt
Schema::create('task_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained()->onDelete('cascade');
    $table->foreignId('technician_id')->constrained()->onDelete('cascade');
    $table->enum('role', ['responsible', 'contributor', 'reviewer'])->default('responsible');
    $table->date('assigned_at')->default(DB::raw('CURRENT_DATE'));
    $table->timestamps();
    
    $table->unique(['task_id', 'technician_id']);
});
```

### 4. **Task Dependencies (N:N)**
**Problem:** No way to define task order/dependencies (critical for Gantt!)
**Required:** Self-referencing pivot table

```php
// Missing Migration
Schema::create('task_dependencies', function (Blueprint $table) {
    $table->id();
    $table->foreignId('task_id')->constrained()->onDelete('cascade'); // Dependent task
    $table->foreignId('depends_on_task_id')->constrained('tasks')->onDelete('cascade'); // Prerequisite
    $table->enum('dependency_type', ['finish_to_start', 'start_to_start', 'finish_to_finish', 'start_to_finish'])
          ->default('finish_to_start');
    $table->integer('lag_days')->default(0); // Delay after predecessor
    $table->timestamps();
    
    $table->unique(['task_id', 'depends_on_task_id']);
});
```

---

## 🎯 Gantt Chart Implementation

### Recommended Laravel Package: **Laravel Gantt**
**Package:** `mediconesystems/livewire-gantt` or custom with **DHTMLX Gantt**

### Alternative: DHTMLX Gantt (Professional Solution)
- **Frontend Library:** `dhtmlx-gantt` (MIT license for non-commercial)
- **Laravel Integration:** REST API + React component
- **Features:** Drag-drop, dependencies, resource allocation, critical path

---

## Gantt Chart Data Structure

### Required Fields in `tasks` Table

```php
// Migration: Add Gantt-required fields to tasks
Schema::table('tasks', function (Blueprint $table) {
    // Planning fields
    $table->date('start_date')->nullable()->after('task_type');
    $table->date('end_date')->nullable()->after('start_date');
    $table->integer('duration_days')->nullable()->after('end_date'); // Calculated or manual
    $table->decimal('estimated_hours', 8, 2)->nullable()->after('duration_days');
    $table->decimal('actual_hours', 8, 2)->default(0)->after('estimated_hours');
    
    // Progress tracking
    $table->integer('progress_percentage')->default(0)->after('actual_hours'); // 0-100
    $table->enum('priority', ['low', 'normal', 'high', 'critical'])->default('normal')->after('progress_percentage');
    
    // Assignment
    $table->foreignId('assigned_technician_id')
          ->nullable()
          ->after('priority')
          ->constrained('technicians')
          ->onDelete('set null');
    
    // Gantt-specific
    $table->integer('parent_task_id')->nullable()->after('project_id'); // For subtasks
    $table->integer('sort_order')->default(0)->after('parent_task_id');
    $table->string('color', 7)->nullable()->after('sort_order'); // Hex color for Gantt bar
    
    // Indexes
    $table->index(['project_id', 'start_date']);
    $table->index(['assigned_technician_id', 'is_active']);
    $table->foreign('parent_task_id')->references('id')->on('tasks')->onDelete('cascade');
});
```

### Gantt API Endpoints

```php
// routes/api.php

Route::prefix('gantt')->middleware(['auth:sanctum'])->group(function () {
    // Get Gantt data for a project
    Route::get('/projects/{project}/tasks', [GanttController::class, 'getTasks']);
    
    // Update task (drag-drop in Gantt)
    Route::put('/tasks/{task}', [GanttController::class, 'updateTask']);
    
    // Create dependency link
    Route::post('/dependencies', [GanttController::class, 'createDependency']);
    
    // Delete dependency link
    Route::delete('/dependencies/{dependency}', [GanttController::class, 'deleteDependency']);
    
    // Assign technician to task
    Route::post('/tasks/{task}/assign', [GanttController::class, 'assignTechnician']);
    
    // Get resource utilization (technician workload)
    Route::get('/projects/{project}/resources', [GanttController::class, 'getResourceUtilization']);
    
    // Auto-schedule (critical path calculation)
    Route::post('/projects/{project}/auto-schedule', [GanttController::class, 'autoSchedule']);
});
```

### Gantt Controller Example

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GanttController extends Controller
{
    /**
     * Get Gantt chart data for a project
     */
    public function getTasks(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        
        $tasks = Task::where('project_id', $project->id)
            ->with(['assignedTechnician', 'dependencies'])
            ->orderBy('sort_order')
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'text' => $task->name,
                    'start_date' => $task->start_date?->format('Y-m-d'),
                    'end_date' => $task->end_date?->format('Y-m-d'),
                    'duration' => $task->duration_days,
                    'progress' => $task->progress_percentage / 100,
                    'parent' => $task->parent_task_id ?? 0,
                    'type' => $task->parent_task_id ? 'task' : 'project',
                    'assignee' => $task->assignedTechnician?->name,
                    'color' => $task->color,
                    'priority' => $task->priority,
                ];
            });
        
        $dependencies = TaskDependency::whereIn('task_id', $project->tasks->pluck('id'))
            ->get()
            ->map(function ($dep) {
                return [
                    'id' => $dep->id,
                    'source' => $dep->depends_on_task_id,
                    'target' => $dep->task_id,
                    'type' => $this->mapDependencyType($dep->dependency_type),
                    'lag' => $dep->lag_days,
                ];
            });
        
        return response()->json([
            'data' => $tasks,
            'links' => $dependencies,
        ]);
    }
    
    /**
     * Update task from Gantt drag-drop
     */
    public function updateTask(Request $request, Task $task): JsonResponse
    {
        $this->authorize('update', $task);
        
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'duration' => 'nullable|integer|min:1',
            'progress' => 'nullable|numeric|between:0,100',
            'assigned_technician_id' => 'nullable|exists:technicians,id',
        ]);
        
        if (isset($validated['progress'])) {
            $validated['progress_percentage'] = $validated['progress'];
            unset($validated['progress']);
        }
        
        // Calculate duration if dates provided
        if (isset($validated['start_date']) && isset($validated['end_date'])) {
            $start = \Carbon\Carbon::parse($validated['start_date']);
            $end = \Carbon\Carbon::parse($validated['end_date']);
            $validated['duration_days'] = $start->diffInDays($end) + 1;
        }
        
        $task->update($validated);
        
        return response()->json($task);
    }
    
    /**
     * Create task dependency
     */
    public function createDependency(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id' => 'required|exists:tasks,id',
            'depends_on_task_id' => 'required|exists:tasks,id',
            'dependency_type' => 'required|in:finish_to_start,start_to_start,finish_to_finish,start_to_finish',
            'lag_days' => 'nullable|integer',
        ]);
        
        // Prevent circular dependencies
        if ($this->hasCircularDependency($validated['task_id'], $validated['depends_on_task_id'])) {
            return response()->json(['error' => 'Circular dependency detected'], 422);
        }
        
        $dependency = TaskDependency::create($validated);
        
        return response()->json($dependency, 201);
    }
    
    /**
     * Get resource utilization (workload per technician)
     */
    public function getResourceUtilization(Project $project): JsonResponse
    {
        $this->authorize('view', $project);
        
        $utilization = Task::where('project_id', $project->id)
            ->whereNotNull('assigned_technician_id')
            ->with('assignedTechnician')
            ->get()
            ->groupBy('assigned_technician_id')
            ->map(function ($tasks, $technicianId) {
                $technician = $tasks->first()->assignedTechnician;
                return [
                    'technician_id' => $technicianId,
                    'name' => $technician->name,
                    'total_tasks' => $tasks->count(),
                    'total_estimated_hours' => $tasks->sum('estimated_hours'),
                    'total_actual_hours' => $tasks->sum('actual_hours'),
                    'average_progress' => $tasks->avg('progress_percentage'),
                    'tasks' => $tasks->map(fn($t) => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'estimated_hours' => $t->estimated_hours,
                        'start_date' => $t->start_date,
                        'end_date' => $t->end_date,
                    ]),
                ];
            })
            ->values();
        
        return response()->json($utilization);
    }
    
    private function hasCircularDependency(int $taskId, int $dependsOnId): bool
    {
        // Simple circular check - could be enhanced with recursive traversal
        $existingDep = TaskDependency::where('task_id', $dependsOnId)
            ->where('depends_on_task_id', $taskId)
            ->exists();
        
        return $existingDep;
    }
    
    private function mapDependencyType(string $type): int
    {
        return match($type) {
            'finish_to_start' => 0,
            'start_to_start' => 1,
            'finish_to_finish' => 2,
            'start_to_finish' => 3,
            default => 0,
        };
    }
}
```

---

## Frontend: React Gantt Component

### Installation

```bash
npm install dhtmlx-gantt
npm install @types/dhtmlx-gantt --save-dev
```

### Component Implementation

```tsx
// frontend/src/components/Planning/GanttChart.tsx

import React, { useEffect, useRef } from 'react';
import { gantt } from 'dhtmlx-gantt';
import 'dhtmlx-gantt/codebase/dhtmlxgantt.css';
import { Box, Paper, Typography } from '@mui/material';
import api from '../../services/api';

interface GanttChartProps {
  projectId: number;
}

const GanttChart: React.FC<GanttChartProps> = ({ projectId }) => {
  const ganttContainer = useRef<HTMLDivElement>(null);
  
  useEffect(() => {
    if (!ganttContainer.current) return;
    
    // Configure Gantt
    gantt.config.date_format = '%Y-%m-% d';
    gantt.config.columns = [
      { name: 'text', label: 'Task', tree: true, width: 250 },
      { name: 'start_date', label: 'Start', align: 'center', width: 100 },
      { name: 'duration', label: 'Duration', align: 'center', width: 80 },
      { name: 'assignee', label: 'Assigned To', align: 'center', width: 120 },
      { name: 'add', label: '', width: 44 }
    ];
    
    gantt.config.scale_unit = 'day';
    gantt.config.date_scale = '%d %M';
    gantt.config.subscales = [
      { unit: 'month', step: 1, date: '%F %Y' }
    ];
    
    // Enable drag-drop and dependencies
    gantt.config.drag_links = true;
    gantt.config.drag_progress = true;
    gantt.config.drag_resize = true;
    
    // Initialize
    gantt.init(ganttContainer.current);
    
    // Load data
    loadGanttData();
    
    // Event listeners
    gantt.attachEvent('onAfterTaskUpdate', (id, task) => {
      updateTask(id as number, task);
    });
    
    gantt.attachEvent('onAfterLinkAdd', (id, link) => {
      createDependency(link);
    });
    
    return () => {
      gantt.clearAll();
    };
  }, [projectId]);
  
  const loadGanttData = async () => {
    try {
      const response = await api.get(`/gantt/projects/${projectId}/tasks`);
      gantt.parse(response.data);
    } catch (error) {
      console.error('Failed to load Gantt data:', error);
    }
  };
  
  const updateTask = async (taskId: number, task: any) => {
    try {
      await api.put(`/gantt/tasks/${taskId}`, {
        start_date: task.start_date,
        end_date: task.end_date,
        duration: task.duration,
        progress: task.progress * 100,
      });
    } catch (error) {
      console.error('Failed to update task:', error);
      gantt.undo();
    }
  };
  
  const createDependency = async (link: any) => {
    try {
      await api.post('/gantt/dependencies', {
        task_id: link.target,
        depends_on_task_id: link.source,
        dependency_type: 'finish_to_start',
      });
    } catch (error) {
      console.error('Failed to create dependency:', error);
      gantt.deleteLink(link.id);
    }
  };
  
  return (
    <Paper elevation={3} sx={{ p: 2, height: '600px' }}>
      <Typography variant="h6" gutterBottom>
        Project Timeline
      </Typography>
      <Box ref={ganttContainer} sx={{ width: '100%', height: '550px' }} />
    </Paper>
  );
};

export default GanttChart;
```

---

## Implementation Checklist

### Phase 1: Database Restructuring
- [ ] Create `project_members` pivot table migration
- [ ] Create `project_locations` pivot table migration
- [ ] Create `task_assignments` pivot table migration (if using N:N)
- [ ] Create `task_dependencies` pivot table migration
- [ ] Add Gantt fields to `tasks` table (start_date, end_date, duration, progress, etc.)
- [ ] Add `home_location_id` to `technicians` table
- [ ] Run migrations and test relationships

### Phase 2: Model Updates
- [ ] Update `Project` model with `members()` and `locations()` relationships
- [ ] Update `Task` model with `assignments()`, `dependencies()`, `assignedTechnician()`
- [ ] Update `Technician` model with `homeLocation()`, `projectMemberships()`
- [ ] Add helper methods (`isProjectMember()`, `hasDependencies()`, etc.)

### Phase 3: Backend API
- [ ] Create `GanttController` with CRUD operations
- [ ] Implement `getTasks()` - Gantt data format
- [ ] Implement `updateTask()` - Handle drag-drop updates
- [ ] Implement `createDependency()` with circular check
- [ ] Implement `getResourceUtilization()` - Technician workload
- [ ] Add authorization policies for Gantt operations
- [ ] Test API endpoints with Postman

### Phase 4: Frontend Gantt
- [ ] Install `dhtmlx-gantt` package
- [ ] Create `GanttChart.tsx` component
- [ ] Implement data loading from API
- [ ] Handle drag-drop task updates
- [ ] Handle dependency creation/deletion
- [ ] Add resource utilization view
- [ ] Create Gantt page in admin panel
- [ ] Test Gantt interactions

### Phase 5: Admin UI Improvements
- [ ] Replace table-based admin with Gantt view
- [ ] Add project members management UI
- [ ] Add project locations assignment UI
- [ ] Add task assignment interface
- [ ] Migrate all admin text to English
- [ ] Test full workflow: Project → Tasks → Assignments → Gantt

---

## Proposed New Admin Structure

```
/admin
  /projects
    - List (DataGrid)
    - Create/Edit (Dialog)
    - [NEW] Gantt View (per project)
    - [NEW] Members Management
    - [NEW] Locations Assignment
  
  /tasks
    - [REMOVED] - Tasks now managed in Gantt view
  
  /technicians
    - List (DataGrid)
    - Create/Edit (Dialog with home_location_id)
  
  /locations
    - List (DataGrid)
    - Create/Edit (Dialog)
  
  /planning [NEW]
    - Gantt Chart (all projects)
    - Resource Utilization Dashboard
    - Timeline Overview
```

---

**Next Steps:**
1. Review and approve this proposal
2. Start with Phase 1 (database migrations)
3. Implement Phase 2-3 (models and API)
4. Build Phase 4 (Gantt frontend)
5. Polish Phase 5 (admin UI in English)

**Estimated Time:**
- Phase 1: 2 hours
- Phase 2: 3 hours
- Phase 3: 4 hours
- Phase 4: 5 hours
- Phase 5: 3 hours
**Total: ~17 hours**
