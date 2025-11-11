<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class CompleteTestDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates complete test data with all new fields populated.
     */
    public function run(): void
    {
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Clear existing data (in reverse order of dependencies)
        DB::table('expenses')->truncate();
        DB::table('timesheets')->truncate();
        DB::table('project_members')->truncate();
        DB::table('tasks')->truncate();
        DB::table('projects')->truncate();
        DB::table('technicians')->truncate();
        DB::table('locations')->truncate();
        DB::table('model_has_roles')->truncate();
        DB::table('users')->truncate();

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $this->command->info('Creating test users...');
        
        // ==================== USERS ====================
        $adminUser = DB::table('users')->insertGetId([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managerUser = DB::table('users')->insertGetId([
            'name' => 'Carlos Manager',
            'email' => 'carlos.manager@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $techUser = DB::table('users')->insertGetId([
            'name' => 'João Silva',
            'email' => 'joao.silva@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('Assigning roles...');

        // ==================== ROLES ====================
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => $adminUser],
            ['role_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => $managerUser],
            ['role_id' => 3, 'model_type' => 'App\\Models\\User', 'model_id' => $techUser],
        ]);

        $this->command->info('Creating technicians...');

        // ==================== TECHNICIANS ====================
        $techJoao = DB::table('technicians')->insertGetId([
            'user_id' => $techUser,
            'name' => 'João Silva',
            'email' => 'joao.silva@example.com',
            'role' => 'technician',
            'hourly_rate' => 50.00,
            'created_by' => $adminUser,
            'updated_by' => $adminUser,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $techCarlos = DB::table('technicians')->insertGetId([
            'user_id' => $managerUser,
            'name' => 'Carlos Manager',
            'email' => 'carlos.manager@example.com',
            'role' => 'manager',
            'hourly_rate' => 80.00,
            'created_by' => $adminUser,
            'updated_by' => $adminUser,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('Creating locations...');

        // ==================== LOCATIONS ====================
        $locations = [
            [
                'name' => 'PRT - Sede Lisboa',
                'country' => 'Portugal',
                'city' => 'Lisboa',
                'address' => 'Av. da Liberdade 100',
                'postal_code' => '1250-145',
                'latitude' => 38.71990000,
                'longitude' => -9.14490000,
                'is_active' => true,
                'created_by' => $adminUser,
            ],
            [
                'name' => 'PRT - Centro Colombo',
                'country' => 'Portugal',
                'city' => 'Lisboa',
                'address' => 'Av. Lusíada 7',
                'postal_code' => '1500-392',
                'latitude' => 38.74870000,
                'longitude' => -9.20040000,
                'is_active' => true,
                'created_by' => $adminUser,
            ],
            [
                'name' => 'PRT - Porto Office',
                'country' => 'Portugal',
                'city' => 'Porto',
                'address' => 'Rua de Santa Catarina 1000',
                'postal_code' => '4000-447',
                'latitude' => 41.15170000,
                'longitude' => -8.61080000,
                'is_active' => true,
                'created_by' => $adminUser,
            ],
            [
                'name' => 'PRT - Coimbra',
                'country' => 'Portugal',
                'city' => 'Coimbra',
                'address' => 'Av. Fernão Magalhães 50',
                'postal_code' => '3000-175',
                'latitude' => 40.21100000,
                'longitude' => -8.42920000,
                'is_active' => true,
                'created_by' => $adminUser,
            ],
            [
                'name' => 'Remote Work',
                'country' => 'Remote',
                'city' => 'Global',
                'address' => null,
                'postal_code' => null,
                'latitude' => null,
                'longitude' => null,
                'is_active' => true,
                'created_by' => $adminUser,
            ],
        ];

        $locationIds = [];
        foreach ($locations as $location) {
            $locationIds[] = DB::table('locations')->insertGetId(array_merge($location, [
                'updated_by' => $adminUser,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('Creating projects...');

        // ==================== PROJECTS ====================
        $project1 = DB::table('projects')->insertGetId([
            'name' => 'Website Redesign',
            'description' => 'Complete website redesign project',
            'manager_id' => $managerUser,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'status' => 'active',
            'created_by' => $adminUser,
            'updated_by' => $adminUser,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $project2 = DB::table('projects')->insertGetId([
            'name' => 'Mobile App Development',
            'description' => 'Native mobile application',
            'manager_id' => $managerUser,
            'start_date' => '2025-06-01',
            'end_date' => '2025-12-31',
            'status' => 'active',
            'created_by' => $adminUser,
            'updated_by' => $adminUser,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command->info('Creating project members...');

        // ==================== PROJECT MEMBERS ====================
        DB::table('project_members')->insert([
            [
                'project_id' => $project1,
                'user_id' => $techUser,
                'project_role' => 'member',
                'expense_role' => 'member',
                'created_by' => $managerUser,
                'updated_by' => $managerUser,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project1,
                'user_id' => $managerUser,
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'created_by' => $managerUser,
                'updated_by' => $managerUser,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'project_id' => $project2,
                'user_id' => $techUser,
                'project_role' => 'member',
                'expense_role' => 'member',
                'created_by' => $managerUser,
                'updated_by' => $managerUser,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command->info('Creating tasks...');

        // ==================== TASKS ====================
        $tasks = [
            ['project_id' => $project1, 'name' => 'Frontend Development', 'description' => 'UI/UX implementation'],
            ['project_id' => $project1, 'name' => 'Backend Development', 'description' => 'API and database'],
            ['project_id' => $project1, 'name' => 'Testing', 'description' => 'QA and testing'],
            ['project_id' => $project2, 'name' => 'Mobile UI', 'description' => 'Mobile interface'],
            ['project_id' => $project2, 'name' => 'API Integration', 'description' => 'Backend integration'],
        ];

        $taskIds = [];
        foreach ($tasks as $task) {
            $taskIds[$task['project_id']][] = DB::table('tasks')->insertGetId(array_merge($task, [
                'created_by' => $managerUser,
                'updated_by' => $managerUser,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('Creating timesheets with complete data...');

        // ==================== TIMESHEETS ====================
        $timesheets = [
            // João Silva - November 2025 (current month)
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[0],
                'date' => '2025-11-04',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'hours_worked' => 8.00,
                'description' => 'Frontend development - Homepage layout',
                'status' => 'approved',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][1],
                'location_id' => $locationIds[4],
                'date' => '2025-11-05',
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
                'hours_worked' => 4.00,
                'description' => 'Backend API development',
                'status' => 'approved',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[1],
                'date' => '2025-11-06',
                'start_time' => '09:00:00',
                'end_time' => '18:00:00',
                'hours_worked' => 8.00,
                'description' => 'Component development',
                'status' => 'submitted',
                'created_by' => $techUser,
                'updated_by' => $techUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][2],
                'location_id' => $locationIds[0],
                'date' => '2025-11-07',
                'start_time' => '09:30:00',
                'end_time' => '13:00:00',
                'hours_worked' => 3.50,
                'description' => 'Unit testing',
                'status' => 'submitted',
                'created_by' => $techUser,
                'updated_by' => $techUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[4],
                'date' => '2025-11-08',
                'start_time' => '08:00:00',
                'end_time' => '12:30:00',
                'hours_worked' => 4.50,
                'description' => 'Bug fixes and optimizations',
                'status' => 'rejected',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],
            
            // Carlos Manager - November 2025
            [
                'technician_id' => $techCarlos,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[3],
                'date' => '2025-11-03',
                'start_time' => '10:00:00',
                'end_time' => '16:00:00',
                'hours_worked' => 6.00,
                'description' => 'Project oversight and code review',
                'status' => 'closed',
                'created_by' => $managerUser,
                'updated_by' => $adminUser,
            ],
            [
                'technician_id' => $techCarlos,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][1],
                'location_id' => $locationIds[1],
                'date' => '2025-11-12',
                'start_time' => '11:00:00',
                'end_time' => '15:30:00',
                'hours_worked' => 4.50,
                'description' => 'Architecture planning',
                'status' => 'submitted',
                'created_by' => $managerUser,
                'updated_by' => $managerUser,
            ],

            // João Silva - October 2025
            [
                'technician_id' => $techJoao,
                'project_id' => $project2,
                'task_id' => $taskIds[$project2][0],
                'location_id' => $locationIds[2],
                'date' => '2025-10-15',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'hours_worked' => 8.00,
                'description' => 'Mobile UI development',
                'status' => 'closed',
                'created_by' => $techUser,
                'updated_by' => $adminUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project2,
                'task_id' => $taskIds[$project2][1],
                'location_id' => $locationIds[4],
                'date' => '2025-10-20',
                'start_time' => '10:30:00',
                'end_time' => '16:00:00',
                'hours_worked' => 5.50,
                'description' => 'API integration work',
                'status' => 'approved',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project2,
                'task_id' => $taskIds[$project2][0],
                'location_id' => $locationIds[3],
                'date' => '2025-10-25',
                'start_time' => '08:30:00',
                'end_time' => '14:00:00',
                'hours_worked' => 5.50,
                'description' => 'Mobile screens implementation',
                'status' => 'approved',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],

            // Mixed week with different durations
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[0],
                'date' => '2025-11-18',
                'start_time' => '09:00:00',
                'end_time' => '11:15:00',
                'hours_worked' => 2.25,
                'description' => 'Short morning session',
                'status' => 'submitted',
                'created_by' => $techUser,
                'updated_by' => $techUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][1],
                'location_id' => $locationIds[4],
                'date' => '2025-11-19',
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'hours_worked' => 8.00,
                'description' => 'Full day development',
                'status' => 'submitted',
                'created_by' => $techUser,
                'updated_by' => $techUser,
            ],
            [
                'technician_id' => $techJoao,
                'project_id' => $project1,
                'task_id' => $taskIds[$project1][0],
                'location_id' => $locationIds[1],
                'date' => '2025-11-20',
                'start_time' => '13:00:00',
                'end_time' => '18:30:00',
                'hours_worked' => 5.50,
                'description' => 'Afternoon coding session',
                'status' => 'approved',
                'created_by' => $techUser,
                'updated_by' => $managerUser,
            ],
        ];

        foreach ($timesheets as $timesheet) {
            DB::table('timesheets')->insert(array_merge($timesheet, [
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }

        $this->command->info('✅ Test data created successfully!');
        $this->command->info('');
        $this->command->info('Login credentials:');
        $this->command->info('  Admin:   admin@example.com / password');
        $this->command->info('  Manager: carlos.manager@example.com / password');
        $this->command->info('  Tech:    joao.silva@example.com / password');
        $this->command->info('');
        $this->command->info('All timesheets include start_time, end_time, created_by, and updated_by fields.');
        $this->command->info('Status values: draft, submitted, approved, rejected, closed');
    }
}
