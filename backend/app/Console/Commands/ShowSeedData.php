<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\Location;

class ShowSeedData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'show:seed-data {type?}';

    /**
     * The console command description.
     */
    protected $description = 'Display seeded data for tasks and locations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $type = $this->argument('type');

        if (!$type || $type === 'tasks') {
            $this->showTasks();
        }

        if (!$type || $type === 'locations') {
            $this->showLocations();
        }

        return 0;
    }

    private function showTasks()
    {
        $this->info('🛠️  IT TASKS OVERVIEW');
        $this->line('─────────────────────────────────────────────────────');
        
        $tasks = Task::with('project')->get()->groupBy('project.name');
        
        foreach ($tasks as $projectName => $projectTasks) {
            $this->line("📁 {$projectName} ({$projectTasks->count()} tasks)");
            
            foreach ($projectTasks as $task) {
                $status = $task->is_active ? '✅' : '❌';
                $type = ucfirst($task->task_type);
                $this->line("   {$status} {$task->name} [{$type}]");
            }
            $this->line('');
        }
        
        $this->info('Total Tasks: ' . Task::count());
        $this->info('Active Tasks: ' . Task::where('is_active', true)->count());
    }

    private function showLocations()
    {
        $this->info('🌍 PROFESSIONAL LOCATIONS OVERVIEW');
        $this->line('─────────────────────────────────────────────────────');
        
        $locations = Location::all()->groupBy('country');
        
        foreach ($locations as $country => $countryLocations) {
            $flag = match($country) {
                'PRT' => '🇵🇹 Portugal',
                'FRA' => '🇫🇷 France', 
                'ESP' => '🇪🇸 Spain',
                default => $country
            };
            
            $this->line("{$flag} ({$countryLocations->count()} locations)");
            
            $cities = $countryLocations->groupBy('city');
            foreach ($cities as $city => $cityLocations) {
                $this->line("  📍 {$city} ({$cityLocations->count()} locations)");
                
                foreach ($cityLocations as $location) {
                    $status = $location->is_active ? '✅' : '❌';
                    $coords = "({$location->latitude}, {$location->longitude})";
                    $this->line("     {$status} {$location->name}");
                    $this->line("        📧 {$location->address}, {$location->postal_code}");
                    $this->line("        🗺️  {$coords}");
                }
            }
            $this->line('');
        }
        
        $this->info('Total Locations: ' . Location::count());
        $this->info('Active Locations: ' . Location::where('is_active', true)->count());
    }
}