<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;
use App\Models\Task;
use App\Models\Resource;
use App\Models\Location;

class GanttDemoSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::create(['name' => 'Payroll Offline – Sprint 1']);

        $task1 = Task::create([
            'project_id' => $project->id,
            'name' => 'Setup DB',
            'start_date' => '2025-11-07',
            'end_date' => '2025-11-10',
            'progress' => 100,
            'dependencies' => null,
        ]);
        $task2 = Task::create([
            'project_id' => $project->id,
            'name' => 'API Payroll – MVP',
            'start_date' => '2025-11-11',
            'end_date' => '2025-11-14',
            'progress' => 10,
            'dependencies' => $task1->id,
        ]);
        $task3 = Task::create([
            'project_id' => $project->id,
            'name' => 'Gantt UI Integration',
            'start_date' => '2025-11-12',
            'end_date' => '2025-11-18',
            'progress' => 0,
            'dependencies' => $task1->id . ',' . $task2->id,
        ]);
    }
}
