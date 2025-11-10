<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;
use App\Models\Task;
use App\Models\Resource;
use App\Models\Location;

class PlanningDemoSeeder extends Seeder
{
    public function run(): void
    {
        $project = Project::first();
        if (!$project) return;

        $resource1 = Resource::create(['name' => 'Ana Silva', 'type' => 'person']);
        $resource2 = Resource::create(['name' => 'Backend Team', 'type' => 'team']);

        $location1 = Location::create(['name' => 'Lisbon HQ', 'country' => 'Portugal', 'timezone' => 'Europe/Lisbon']);
        $location2 = Location::create(['name' => 'Porto DC', 'country' => 'Portugal', 'timezone' => 'Europe/Lisbon']);

        $project->resources()->attach([$resource1->id, $resource2->id]);

        $task = Task::where('project_id', $project->id)->where('name', 'Gantt UI Integration')->first();
        if ($task) {
            $task->resources()->attach($resource1->id, ['allocation' => 60]);
            $task->locations()->attach([$location1->id, $location2->id]);
        }
    }
}
