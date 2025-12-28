<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Project;
use App\Models\Task;
use App\Models\Resource;
use App\Models\Country;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

class PlanningDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');

        $project = Project::first();
        if (!$project) return;

        $this->call(CountriesSeeder::class);

        $resource1 = Resource::on('tenant')->firstOrCreate(['name' => 'Ana Silva', 'type' => 'person']);
        $resource2 = Resource::on('tenant')->firstOrCreate(['name' => 'Backend Team', 'type' => 'team']);

        $portugalId = Country::on('tenant')->where('iso2', 'PT')->value('id');

        $location1 = Location::on('tenant')->updateOrCreate(
            ['name' => 'Lisbon HQ', 'country' => 'Portugal'],
            ['timezone' => 'Europe/Lisbon', 'country_id' => $portugalId]
        );
        $location2 = Location::on('tenant')->updateOrCreate(
            ['name' => 'Porto DC', 'country' => 'Portugal'],
            ['timezone' => 'Europe/Lisbon', 'country_id' => $portugalId]
        );

        $project->resources()->attach([$resource1->id, $resource2->id]);

        $task = Task::where('project_id', $project->id)->where('name', 'Gantt UI Integration')->first();
        if ($task) {
            $task->resources()->attach($resource1->id, ['allocation' => 60]);
            $task->locations()->attach([$location1->id, $location2->id]);
        }
    }
}
