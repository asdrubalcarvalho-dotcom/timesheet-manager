<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Technician;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\Expense;
use Carbon\Carbon;

class DemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $today = now()->startOfDay();

        // Create technicians
        $technicians = [
            [
                'name' => 'João Silva',
                'email' => 'joao.silva@example.com',
                'role' => 'technician',
                'hourly_rate' => 45.00,
                'is_active' => true,
                'worker_id' => 'WRK-001',
                'worker_name' => 'João Silva'
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@example.com',
                'role' => 'technician',
                'hourly_rate' => 50.00,
                'is_active' => true,
                'worker_id' => 'WRK-002',
                'worker_name' => 'Maria Santos'
            ],
            [
                'name' => 'Carlos Manager',
                'email' => 'carlos.manager@example.com',
                'role' => 'manager',
                'hourly_rate' => 80.00,
                'is_active' => true,
                'worker_id' => 'WRK-003',
                'worker_name' => 'Carlos Manager'
            ]
        ];

        foreach ($technicians as $technicianData) {
            Technician::updateOrCreate(
                ['email' => $technicianData['email']],
                $technicianData
            );
        }

        // Create projects
        $projects = [
            [
                'name' => 'Website Redesign',
                'description' => 'Complete redesign of company website',
                'start_date' => $today->copy()->subDays(30),
                'end_date' => $today->copy()->addDays(30),
                'status' => 'active'
            ],
            [
                'name' => 'Mobile App Development',
                'description' => 'New mobile application for customers',
                'start_date' => $today->copy()->subDays(15),
                'end_date' => $today->copy()->addDays(60),
                'status' => 'active'
            ],
            [
                'name' => 'Database Migration',
                'description' => 'Migrate legacy database to new system',
                'start_date' => $today->copy()->subDays(45),
                'end_date' => $today->copy()->subDays(5),
                'status' => 'completed'
            ]
        ];

        foreach ($projects as $projectData) {
            Project::updateOrCreate(
                ['name' => $projectData['name']],
                $projectData
            );
        }

        // Create sample timesheets
        $technician1 = Technician::where('email', 'joao.silva@example.com')->first();
        $technician2 = Technician::where('email', 'maria.santos@example.com')->first();
        $project1 = Project::where('name', 'Website Redesign')->first();
        $project2 = Project::where('name', 'Mobile App Development')->first();

        // Create some basic tasks for our projects
        $basicTasks = [
            [
                'project_id' => $project1->id,
                'name' => 'Frontend Development',
                'description' => 'Development of user interfaces',
                'task_type' => 'installation',
                'is_active' => true
            ],
            [
                'project_id' => $project1->id,
                'name' => 'Backend Development',
                'description' => 'Server-side development',
                'task_type' => 'installation', 
                'is_active' => true
            ],
            [
                'project_id' => $project2->id,
                'name' => 'Database Design',
                'description' => 'Database modeling and optimization',
                'task_type' => 'commissioning',
                'is_active' => true
            ]
        ];

        foreach ($basicTasks as $taskData) {
            \App\Models\Task::updateOrCreate(
                [
                    'project_id' => $taskData['project_id'],
                    'name' => $taskData['name'],
                ],
                $taskData
            );
        }

        // Get the created tasks and locations for the timesheets
        $frontendTask = \App\Models\Task::where('name', 'Frontend Development')->first();
        $backendTask = \App\Models\Task::where('name', 'Backend Development')->first();
        $databaseTask = \App\Models\Task::where('name', 'Database Design')->first();
        
        // Get any locations (they should exist now)
        $officeLocation = \App\Models\Location::first();
        $clientLocation = \App\Models\Location::skip(1)->first() ?: \App\Models\Location::first();

        $timesheets = [
            [
                'technician_id' => $technician1->id,
                'project_id' => $project1->id,
                'date' => $today->copy()->subDays(5),
                'hours_worked' => 8.0,
                'description' => 'Frontend development',
                'status' => 'approved',
                'task_id' => $frontendTask->id,
                'location_id' => $officeLocation->id
            ],
            [
                'technician_id' => $technician1->id,
                'project_id' => $project1->id,
                'date' => $today->copy()->subDays(4),
                'hours_worked' => 7.5,
                'description' => 'CSS styling',
                'status' => 'approved',
                'task_id' => $frontendTask->id,
                'location_id' => $clientLocation->id
            ],
            [
                'technician_id' => $technician2->id,
                'project_id' => $project2->id,
                'date' => $today->copy()->subDays(3),
                'hours_worked' => 8.0,
                'description' => 'Backend API development',
                'status' => 'submitted',
                'task_id' => $backendTask->id,
                'location_id' => $officeLocation->id
            ],
            [
                'technician_id' => $technician2->id,
                'project_id' => $project2->id,
                'date' => $today->copy()->subDays(2),
                'hours_worked' => 6.0,
                'description' => 'Database design',
                'status' => 'draft',
                'task_id' => $databaseTask->id,
                'location_id' => $officeLocation->id
            ]
        ];

        foreach ($timesheets as $timesheetData) {
            Timesheet::updateOrCreate(
                [
                    'technician_id' => $timesheetData['technician_id'],
                    'project_id' => $timesheetData['project_id'],
                    'date' => $timesheetData['date'],
                ],
                $timesheetData
            );
        }

        // Create sample expenses
        $expenses = [
            [
                'technician_id' => $technician1->id,
                'project_id' => $project1->id,
                'date' => $today->copy()->subDays(6),
                'amount' => 25.50,
                'category' => 'travel',
                'description' => 'Uber to client meeting',
                'status' => 'approved'
            ],
            [
                'technician_id' => $technician2->id,
                'project_id' => $project2->id,
                'date' => $today->copy()->subDays(4),
                'amount' => 15.75,
                'category' => 'food',
                'description' => 'Lunch during extended work session',
                'status' => 'submitted'
            ],
            [
                'technician_id' => $technician1->id,
                'project_id' => $project1->id,
                'date' => $today->copy()->subDays(2),
                'amount' => 89.99,
                'category' => 'material',
                'description' => 'Software license for development',
                'status' => 'draft'
            ]
        ];

        foreach ($expenses as $expenseData) {
            Expense::updateOrCreate(
                [
                    'technician_id' => $expenseData['technician_id'],
                    'project_id' => $expenseData['project_id'],
                    'date' => $expenseData['date'],
                    'category' => $expenseData['category'],
                ],
                $expenseData
            );
        }
    }
}
