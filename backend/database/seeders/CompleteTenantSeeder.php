<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Technician;
use App\Models\Project;
use App\Models\Task;
use App\Models\Location;
use App\Models\Resource;
use App\Models\Timesheet;
use App\Models\Expense;
use App\Models\TravelSegment;
use App\Models\ProjectMember;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class CompleteTenantSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates a complete tenant database with realistic data:
     * - Users with roles (Owner, Admin, Manager, Technician)
     * - Technicians linked to users
     * - Projects with members and roles
     * - Tasks with locations and resources
     * - Timesheets with various statuses
     * - Expenses with approval workflow
     * - Travel segments with different directions
     */
    public function run(): void
    {
        // NOTE: Roles and Permissions are already created by RolesAndPermissionsSeeder
        // Skip step 1 when called after reset-data
        
        // 2. Create Users
        $users = $this->createUsers();

        // 3. Create Technicians (linked to users)
        $technicians = $this->createTechnicians($users);

        // 4. Create Locations
        $locations = $this->createLocations();

        // 5. Create Resources
        $resources = $this->createResources();

        // 6. Create Projects
        $projects = $this->createProjects($users['owner']);

        // 7. Assign Project Members with roles
        $this->assignProjectMembers($projects, $users);

        // 8. Create Tasks (linked to projects, locations, resources)
        $tasks = $this->createTasks($projects, $locations, $resources, $users['owner']);

        // 9. Create Timesheets
        $this->createTimesheets($projects, $technicians, $users, $tasks, $locations);

        // 10. Create Expenses
        $this->createExpenses($projects, $technicians, $users);

        // 11. Create Travel Segments
        $this->createTravelSegments($projects, $technicians, $locations, $users);

        $this->command->info('✅ Complete tenant database seeded successfully!');
    }

    private function createRolesAndPermissions(): void
    {
        // Define all permissions
        $permissions = [
            // Timesheet permissions
            'view-timesheets',
            'create-timesheets',
            'edit-own-timesheets',
            'edit-all-timesheets',
            'delete-timesheets',
            'approve-timesheets',
            
            // Expense permissions
            'view-expenses',
            'create-expenses',
            'edit-own-expenses',
            'edit-all-expenses',
            'delete-expenses',
            'approve-expenses',
            'finance-review-expenses',
            
            // Project permissions
            'view-projects',
            'create-projects',
            'edit-projects',
            'delete-projects',
            'manage-projects',
            'manage-locations',
            
            // User permissions
            'view-users',
            'create-users',
            'edit-users',
            'delete-users',
            
            // Travel permissions
            'view-travels',
            'create-travels',
            'edit-travels',
            'delete-travels',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Create roles with permissions
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web']);
        $owner->syncPermissions($permissions); // All permissions

        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions); // All permissions

        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $manager->syncPermissions([
            'view-timesheets', 'create-timesheets', 'edit-own-timesheets', 'approve-timesheets',
            'view-expenses', 'create-expenses', 'edit-own-expenses', 'approve-expenses',
            'view-projects', 'create-projects', 'edit-projects',
            'view-travels', 'create-travels', 'edit-travels',
        ]);

        $technician = Role::firstOrCreate(['name' => 'Technician', 'guard_name' => 'web']);
        $technician->syncPermissions([
            'view-timesheets', 'create-timesheets', 'edit-own-timesheets',
            'view-expenses', 'create-expenses', 'edit-own-expenses',
            'view-projects',
            'view-travels', 'create-travels', 'edit-travels',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'Viewer', 'guard_name' => 'web']);
        $viewer->syncPermissions([
            'view-timesheets', 'view-expenses', 'view-projects', 'view-travels',
        ]);

        $this->command->info('✅ Roles and permissions created');
    }

    private function createUsers(): array
    {
        // Get existing Owner (created during tenant registration)
        $owner = User::whereHas('roles', function($q) {
            $q->where('name', 'Owner');
        })->first();

        if (!$owner) {
            throw new \Exception('Owner user not found. Run this seeder only after tenant registration.');
        }

        $admin = User::firstOrCreate(
            ['email' => 'admin@upg2ai.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password'),
                'role' => 'Admin',
            ]
        );
        $admin->assignRole('Admin');

        $manager1 = User::firstOrCreate(
            ['email' => 'manager1@upg2ai.com'],
            [
                'name' => 'João Silva',
                'password' => Hash::make('password'),
                'role' => 'Manager',
            ]
        );
        $manager1->assignRole('Manager');

        $manager2 = User::firstOrCreate(
            ['email' => 'manager2@upg2ai.com'],
            [
                'name' => 'Maria Santos',
                'password' => Hash::make('password'),
                'role' => 'Manager',
            ]
        );
        $manager2->assignRole('Manager');

        $tech1 = User::firstOrCreate(
            ['email' => 'tech1@upg2ai.com'],
            [
                'name' => 'Pedro Costa',
                'password' => Hash::make('password'),
                'role' => 'Technician',
            ]
        );
        $tech1->assignRole('Technician');

        $tech2 = User::firstOrCreate(
            ['email' => 'tech2@upg2ai.com'],
            [
                'name' => 'Ana Rodrigues',
                'password' => Hash::make('password'),
                'role' => 'Technician',
            ]
        );
        $tech2->assignRole('Technician');

        $tech3 = User::firstOrCreate(
            ['email' => 'tech3@upg2ai.com'],
            [
                'name' => 'Carlos Ferreira',
                'password' => Hash::make('password'),
                'role' => 'Technician',
            ]
        );
        $tech3->assignRole('Technician');

        $this->command->info('✅ Users created');

        return [
            'owner' => $owner,
            'admin' => $admin,
            'manager1' => $manager1,
            'manager2' => $manager2,
            'tech1' => $tech1,
            'tech2' => $tech2,
            'tech3' => $tech3,
        ];
    }

    private function createTechnicians(array $users): array
    {
        $technicians = [];

        // Get existing Owner technician (created during tenant registration)
        $technicians['owner'] = Technician::where('user_id', $users['owner']->id)->first();

        if (!$technicians['owner']) {
            throw new \Exception('Owner technician not found. Should be created during tenant registration.');
        }

        // Note: Owner technician already exists, created during registration/reset
        // No need to create it again here

        $technicians['admin'] = Technician::firstOrCreate(
            ['email' => 'admin@upg2ai.com'],
            [
                'name' => 'Admin User',
                'user_id' => $users['admin']->id,
                'role' => 'manager',
                'hourly_rate' => 70.00,
                'is_active' => true,
                'worker_id' => 'ADM001',
                'worker_name' => 'Admin User',
                'worker_contract_country' => 'PT',
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $technicians['manager1'] = Technician::firstOrCreate(
            ['email' => 'manager1@upg2ai.com'],
            [
                'name' => 'João Silva',
                'user_id' => $users['manager1']->id,
                'role' => 'manager',
                'hourly_rate' => 65.00,
                'is_active' => true,
                'worker_id' => 'MGR001',
                'worker_name' => 'João Silva',
                'worker_contract_country' => 'PT',
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $technicians['manager2'] = Technician::firstOrCreate(
            ['email' => 'manager2@upg2ai.com'],
            [
                'name' => 'Maria Santos',
                'user_id' => $users['manager2']->id,
                'role' => 'manager',
                'hourly_rate' => 65.00,
                'is_active' => true,
                'worker_id' => 'MGR002',
                'worker_name' => 'Maria Santos',
                'worker_contract_country' => 'PT',
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $technicians['tech1'] = Technician::firstOrCreate(
            ['email' => 'tech1@upg2ai.com'],
            [
                'name' => 'Pedro Costa',
                'user_id' => $users['tech1']->id,
                'role' => 'technician',
                'hourly_rate' => 45.00,
                'is_active' => true,
                'worker_id' => 'TEC001',
                'worker_name' => 'Pedro Costa',
                'worker_contract_country' => 'PT',
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $technicians['tech2'] = Technician::firstOrCreate(
            ['email' => 'tech2@upg2ai.com'],
            [
                'name' => 'Ana Rodrigues',
                'user_id' => $users['tech2']->id,
                'role' => 'technician',
                'hourly_rate' => 45.00,
                'is_active' => true,
                'worker_id' => 'TEC002',
                'worker_name' => 'Ana Rodrigues',
                'worker_contract_country' => 'PT',
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $technicians['tech3'] = Technician::firstOrCreate(
            ['email' => 'tech3@upg2ai.com'],
            [
                'name' => 'Carlos Ferreira',
                'user_id' => $users['tech3']->id,
                'role' => 'technician',
                'hourly_rate' => 50.00,
                'is_active' => true,
                'worker_id' => 'TEC003',
                'worker_name' => 'Carlos Ferreira',
                'worker_contract_country' => 'ES', // Spanish contract
                'created_by' => $users['owner']->id,
                'updated_by' => $users['owner']->id,
            ]
        );

        $this->command->info('✅ Technicians created');

        return $technicians;
    }

    private function createLocations(): array
    {
        $locations = [];

        // Portugal locations
        $locations['lisbon_office'] = Location::firstOrCreate(
            ['name' => 'Lisbon Office', 'country' => 'PT'],
            [
                'city' => 'Lisboa',
                'address' => 'Av. da Liberdade, 123',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        $locations['porto_office'] = Location::firstOrCreate(
            ['name' => 'Porto Office', 'country' => 'PT'],
            [
                'city' => 'Porto',
                'address' => 'Rua de Santa Catarina, 456',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        // Spain locations
        $locations['madrid_office'] = Location::firstOrCreate(
            ['name' => 'Madrid Office', 'country' => 'ES'],
            [
                'city' => 'Madrid',
                'address' => 'Gran Vía, 789',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        $locations['barcelona_datacenter'] = Location::firstOrCreate(
            ['name' => 'Barcelona Data Center', 'country' => 'ES'],
            [
                'city' => 'Barcelona',
                'address' => 'Passeig de Gràcia, 321',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        // France locations
        $locations['paris_office'] = Location::firstOrCreate(
            ['name' => 'Paris Office', 'country' => 'FR'],
            [
                'city' => 'Paris',
                'address' => 'Champs-Élysées, 100',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        // Germany locations
        $locations['berlin_office'] = Location::firstOrCreate(
            ['name' => 'Berlin Office', 'country' => 'DE'],
            [
                'city' => 'Berlin',
                'address' => 'Alexanderplatz, 50',
                'is_active' => true,
                'created_by' => 1,
                'updated_by' => 1,
            ]
        );

        $this->command->info('✅ Locations created');

        return $locations;
    }

    private function createResources(): array
    {
        $resources = [];

        // Resources use simplified structure with meta JSON field
        $resources['server1'] = Resource::firstOrCreate(
            ['name' => 'Server Cluster A'],
            [
                'type' => 'equipment',
                'meta' => json_encode([
                    'description' => 'Primary production server cluster',
                    'cost_per_hour' => 15.00,
                ]),
                'user_id' => 1,
            ]
        );

        $resources['server2'] = Resource::firstOrCreate(
            ['name' => 'Development Server'],
            [
                'type' => 'equipment',
                'meta' => json_encode([
                    'description' => 'Development and testing environment',
                    'cost_per_hour' => 8.00,
                ]),
                'user_id' => 1,
            ]
        );

        $resources['software1'] = Resource::firstOrCreate(
            ['name' => 'Enterprise License Pack'],
            [
                'type' => 'software',
                'meta' => json_encode([
                    'description' => 'Software licenses bundle',
                    'cost_per_hour' => 5.00,
                ]),
                'user_id' => 1,
            ]
        );

        $this->command->info('✅ Resources created');

        return $resources;
    }

    private function createProjects($owner): array
    {
        $projects = [];

        // Projects don't have budget or client_name fields
        $projects['ecommerce'] = Project::firstOrCreate(
            ['name' => 'E-Commerce Platform'],
            [
                'description' => 'Development of multi-tenant e-commerce platform',
                'start_date' => Carbon::now()->subMonths(3),
                'end_date' => Carbon::now()->addMonths(6),
                'status' => 'active',
                'manager_id' => $owner->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );

        $projects['mobile_app'] = Project::firstOrCreate(
            ['name' => 'Mobile Banking App'],
            [
                'description' => 'iOS and Android mobile banking application',
                'start_date' => Carbon::now()->subMonths(2),
                'end_date' => Carbon::now()->addMonths(4),
                'status' => 'active',
                'manager_id' => $owner->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );

        $projects['erp_migration'] = Project::firstOrCreate(
            ['name' => 'ERP System Migration'],
            [
                'description' => 'Migration from legacy ERP to SAP S/4HANA',
                'start_date' => Carbon::now()->subMonths(1),
                'end_date' => Carbon::now()->addMonths(8),
                'status' => 'active',
                'manager_id' => $owner->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );

        $projects['infrastructure'] = Project::firstOrCreate(
            ['name' => 'Cloud Infrastructure Setup'],
            [
                'description' => 'AWS cloud infrastructure deployment and optimization',
                'start_date' => Carbon::now()->subWeeks(2),
                'end_date' => Carbon::now()->addMonths(3),
                'status' => 'active',
                'manager_id' => $owner->id,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );

        $this->command->info('✅ Projects created');

        return $projects;
    }

    private function assignProjectMembers(array $projects, array $users): void
    {
        // Add Owner to E-Commerce Platform as manager
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['ecommerce']->id, 'user_id' => $users['owner']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        // E-Commerce Platform - Manager1 leads, Tech1 and Tech2 as members
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['ecommerce']->id, 'user_id' => $users['manager1']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['ecommerce']->id, 'user_id' => $users['tech1']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['ecommerce']->id, 'user_id' => $users['tech2']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        // Mobile Banking App - Owner as manager, Manager2 leads, Tech2 and Tech3 as members
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['mobile_app']->id, 'user_id' => $users['owner']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['mobile_app']->id, 'user_id' => $users['manager2']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['mobile_app']->id, 'user_id' => $users['tech2']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['mobile_app']->id, 'user_id' => $users['tech3']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'member', // Finance member
            ]
        );

        // Add Owner to ERP Migration as manager
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['erp_migration']->id, 'user_id' => $users['owner']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        // ERP Migration - Manager1 leads, all techs as members
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['erp_migration']->id, 'user_id' => $users['manager1']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'manager',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['erp_migration']->id, 'user_id' => $users['tech1']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['erp_migration']->id, 'user_id' => $users['tech3']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        // Add Owner to Cloud Infrastructure as MEMBER (exception - not manager)
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['infrastructure']->id, 'user_id' => $users['owner']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        // Cloud Infrastructure - Manager2 leads, Tech1 as member
        ProjectMember::firstOrCreate(
            ['project_id' => $projects['infrastructure']->id, 'user_id' => $users['manager2']->id],
            [
                'project_role' => 'manager',
                'expense_role' => 'manager',
                'finance_role' => 'none',
            ]
        );

        ProjectMember::firstOrCreate(
            ['project_id' => $projects['infrastructure']->id, 'user_id' => $users['tech1']->id],
            [
                'project_role' => 'member',
                'expense_role' => 'member',
                'finance_role' => 'none',
            ]
        );

        $this->command->info('✅ Project members assigned');
    }

    private function createTasks(array $projects, array $locations, array $resources, $owner): array
    {
        $tasks = [];

        // Tasks don't have status or priority fields - only task_type
        // E-Commerce Platform tasks
        $tasks['ecom_backend'] = Task::firstOrCreate(
            ['name' => 'Backend API Development', 'project_id' => $projects['ecommerce']->id],
            [
                'description' => 'Develop RESTful API for e-commerce platform',
                'start_date' => Carbon::now()->subMonths(2),
                'end_date' => Carbon::now()->addMonths(2),
                'task_type' => 'installation',
                'estimated_hours' => 320,
                'progress' => 45,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['ecom_backend']->locations()->sync([$locations['lisbon_office']->id]);
        $tasks['ecom_backend']->resources()->sync([$resources['server1']->id]);

        $tasks['ecom_frontend'] = Task::firstOrCreate(
            ['name' => 'Frontend React App', 'project_id' => $projects['ecommerce']->id],
            [
                'description' => 'Build responsive React frontend',
                'start_date' => Carbon::now()->subMonths(1),
                'end_date' => Carbon::now()->addMonths(3),
                'task_type' => 'installation',
                'estimated_hours' => 280,
                'progress' => 30,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['ecom_frontend']->locations()->sync([$locations['porto_office']->id]);

        // Mobile Banking App tasks
        $tasks['mobile_ios'] = Task::firstOrCreate(
            ['name' => 'iOS App Development', 'project_id' => $projects['mobile_app']->id],
            [
                'description' => 'Develop native iOS banking application',
                'start_date' => Carbon::now()->subMonths(1),
                'end_date' => Carbon::now()->addMonths(3),
                'task_type' => 'installation',
                'estimated_hours' => 240,
                'progress' => 50,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['mobile_ios']->locations()->sync([$locations['madrid_office']->id]);

        $tasks['mobile_android'] = Task::firstOrCreate(
            ['name' => 'Android App Development', 'project_id' => $projects['mobile_app']->id],
            [
                'description' => 'Develop native Android banking application',
                'start_date' => Carbon::now()->subMonths(1),
                'end_date' => Carbon::now()->addMonths(3),
                'task_type' => 'installation',
                'estimated_hours' => 240,
                'progress' => 50,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['mobile_android']->locations()->sync([$locations['barcelona_datacenter']->id]);

        // ERP Migration tasks
        $tasks['erp_analysis'] = Task::firstOrCreate(
            ['name' => 'System Analysis', 'project_id' => $projects['erp_migration']->id],
            [
                'description' => 'Analyze legacy system and plan migration',
                'start_date' => Carbon::now()->subWeeks(3),
                'end_date' => Carbon::now()->addWeeks(2),
                'task_type' => 'inspection',
                'estimated_hours' => 160,
                'progress' => 70,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['erp_analysis']->locations()->sync([$locations['paris_office']->id, $locations['berlin_office']->id]);
        $tasks['erp_analysis']->resources()->sync([$resources['software1']->id]);

        // Cloud Infrastructure tasks
        $tasks['cloud_setup'] = Task::firstOrCreate(
            ['name' => 'AWS Environment Setup', 'project_id' => $projects['infrastructure']->id],
            [
                'description' => 'Configure AWS VPC, EC2, RDS, and S3',
                'start_date' => Carbon::now()->subWeeks(1),
                'end_date' => Carbon::now()->addWeeks(6),
                'task_type' => 'commissioning',
                'estimated_hours' => 120,
                'progress' => 25,
                'is_active' => true,
                'created_by' => $owner->id,
                'updated_by' => $owner->id,
            ]
        );
        $tasks['cloud_setup']->locations()->sync([$locations['lisbon_office']->id]);
        $tasks['cloud_setup']->resources()->sync([$resources['server2']->id]);

        $this->command->info('✅ Tasks created');

        return $tasks;
    }

    private function createTimesheets(array $projects, array $technicians, array $users, array $tasks, array $locations): void
    {
        // Map tasks to their first location for timesheet entries
        $taskLocations = [
            'ecom_backend' => $locations['lisbon_office']->id,
            'ecom_frontend' => $locations['porto_office']->id,
            'mobile_ios' => $locations['madrid_office']->id,
            'mobile_android' => $locations['barcelona_datacenter']->id,
            'erp_analysis' => $locations['paris_office']->id,
            'cloud_setup' => $locations['lisbon_office']->id,
        ];
        
        // Create timesheets for the last 30 days
        for ($day = 30; $day >= 0; $day--) {
            $date = Carbon::now()->subDays($day);
            
            // Skip weekends
            if ($date->isWeekend()) {
                continue;
            }

            // Tech1 - E-Commerce project (Backend API task)
            if ($day % 2 == 0) { // Every other day
                Timesheet::firstOrCreate(
                    [
                        'technician_id' => $technicians['tech1']->id,
                        'project_id' => $projects['ecommerce']->id,
                        'task_id' => $tasks['ecom_backend']->id,
                        'date' => $date->format('Y-m-d'),
                    ],
                    [
                        'location_id' => $taskLocations['ecom_backend'],
                        'start_time' => '09:00',
                        'end_time' => '18:00',
                        'hours_worked' => 8,
                        'description' => 'Backend API development and testing',
                        'status' => $day < 7 ? 'draft' : ($day < 14 ? 'submitted' : 'approved'),
                        'hour_type' => 'working',
                        'job_status' => 'ongoing',
                        'created_by' => $users['tech1']->id,
                        'updated_by' => $users['tech1']->id,
                    ]
                );
            }

            // Tech2 - Mobile App project (iOS task)
            if ($day % 3 != 0) { // Most days
                Timesheet::firstOrCreate(
                    [
                        'technician_id' => $technicians['tech2']->id,
                        'project_id' => $projects['mobile_app']->id,
                        'task_id' => $tasks['mobile_ios']->id,
                        'date' => $date->format('Y-m-d'),
                    ],
                    [
                        'location_id' => $taskLocations['mobile_ios'],
                        'start_time' => '08:30',
                        'end_time' => '17:30',
                        'hours_worked' => 8,
                        'description' => 'iOS app development',
                        'status' => $day < 5 ? 'draft' : ($day < 10 ? 'submitted' : 'approved'),
                        'hour_type' => 'working',
                        'job_status' => 'ongoing',
                        'created_by' => $users['tech2']->id,
                        'updated_by' => $users['tech2']->id,
                    ]
                );
            }

            // Tech3 - ERP Migration project (Analysis task)
            if ($day % 2 == 1) { // Alternate days
                Timesheet::firstOrCreate(
                    [
                        'technician_id' => $technicians['tech3']->id,
                        'project_id' => $projects['erp_migration']->id,
                        'task_id' => $tasks['erp_analysis']->id,
                        'date' => $date->format('Y-m-d'),
                    ],
                    [
                        'location_id' => $taskLocations['erp_analysis'],
                        'start_time' => '09:00',
                        'end_time' => '19:00',
                        'hours_worked' => 9,
                        'description' => 'System analysis and migration planning',
                        'status' => $day < 3 ? 'draft' : ($day < 8 ? 'submitted' : 'approved'),
                        'hour_type' => 'working',
                        'job_status' => 'ongoing',
                        'created_by' => $users['tech3']->id,
                        'updated_by' => $users['tech3']->id,
                    ]
                );
            }
        }

        $this->command->info('✅ Timesheets created');
    }

    private function createExpenses(array $projects, array $technicians, array $users): void
    {
        // Tech1 expenses - E-Commerce project
        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['ecommerce']->id,
                'date' => Carbon::now()->subDays(15)->format('Y-m-d'),
            ],
            [
                'category' => 'travel',
                'amount' => 85.50,
                'description' => 'Train ticket Lisboa-Porto',
                'status' => 'approved',
                'expense_type' => 'reimbursement',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['ecommerce']->id,
                'date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            ],
            [
                'category' => 'meals',
                'amount' => 45.00,
                'description' => 'Client lunch meeting',
                'status' => 'submitted',
                'expense_type' => 'reimbursement',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        // Tech2 expenses - Mobile App project
        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech2']->id,
                'project_id' => $projects['mobile_app']->id,
                'date' => Carbon::now()->subDays(8)->format('Y-m-d'),
            ],
            [
                'category' => 'accommodation',
                'amount' => 320.00,
                'description' => 'Hotel Madrid - 4 nights',
                'status' => 'finance_review',
                'expense_type' => 'company_card',
                'created_by' => $users['tech2']->id,
                'updated_by' => $users['tech2']->id,
            ]
        );

        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech2']->id,
                'project_id' => $projects['mobile_app']->id,
                'date' => Carbon::now()->subDays(5)->format('Y-m-d'),
            ],
            [
                'category' => 'equipment',
                'amount' => 1299.00,
                'description' => 'MacBook Pro M3 for development',
                'status' => 'finance_approved',
                'expense_type' => 'company_card',
                'created_by' => $users['tech2']->id,
                'updated_by' => $users['tech2']->id,
            ]
        );

        // Tech3 expenses - ERP Migration
        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech3']->id,
                'project_id' => $projects['erp_migration']->id,
                'date' => Carbon::now()->subDays(3)->format('Y-m-d'),
            ],
            [
                'category' => 'travel',
                'amount' => 450.00,
                'description' => 'Flight Madrid-Paris round trip',
                'status' => 'submitted',
                'expense_type' => 'company_card',
                'created_by' => $users['tech3']->id,
                'updated_by' => $users['tech3']->id,
            ]
        );

        // Mileage expense example
        Expense::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['infrastructure']->id,
                'date' => Carbon::now()->subDays(2)->format('Y-m-d'),
            ],
            [
                'category' => 'mileage',
                'amount' => 60.00,
                'description' => 'Site visit - Lisbon to Cascais',
                'status' => 'draft',
                'expense_type' => 'mileage',
                'distance_km' => 150.00,
                'rate_per_km' => 0.40,
                'vehicle_type' => 'car',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        $this->command->info('✅ Expenses created');
    }

    private function createTravelSegments(array $projects, array $technicians, array $locations, array $users): void
    {
        // Tech1 (PT contract) - Departure from PT to ES
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['ecommerce']->id,
                'travel_date' => Carbon::now()->subDays(15),
            ],
            [
                'start_at' => Carbon::now()->subDays(15)->setTime(8, 30),
                'end_at' => Carbon::now()->subDays(15)->setTime(11, 15),
                'origin_country' => 'PT',
                'origin_location_id' => $locations['lisbon_office']->id,
                'destination_country' => 'ES',
                'destination_location_id' => $locations['madrid_office']->id,
                'status' => 'completed',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        // Tech1 - Arrival from ES back to PT
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['ecommerce']->id,
                'travel_date' => Carbon::now()->subDays(10),
            ],
            [
                'start_at' => Carbon::now()->subDays(10)->setTime(14, 0),
                'end_at' => Carbon::now()->subDays(10)->setTime(16, 45),
                'origin_country' => 'ES',
                'origin_location_id' => $locations['madrid_office']->id,
                'destination_country' => 'PT',
                'destination_location_id' => $locations['lisbon_office']->id,
                'status' => 'completed',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        // Tech2 (PT contract) - Internal PT movement
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech2']->id,
                'project_id' => $projects['mobile_app']->id,
                'travel_date' => Carbon::now()->subDays(7),
            ],
            [
                'start_at' => Carbon::now()->subDays(7)->setTime(9, 0),
                'end_at' => Carbon::now()->subDays(7)->setTime(12, 30),
                'origin_country' => 'PT',
                'origin_location_id' => $locations['lisbon_office']->id,
                'destination_country' => 'PT',
                'destination_location_id' => $locations['porto_office']->id,
                'status' => 'completed',
                'created_by' => $users['tech2']->id,
                'updated_by' => $users['tech2']->id,
            ]
        );

        // Tech3 (ES contract) - Departure from ES to FR
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech3']->id,
                'project_id' => $projects['erp_migration']->id,
                'travel_date' => Carbon::now()->subDays(5),
            ],
            [
                'start_at' => Carbon::now()->subDays(5)->setTime(10, 15),
                'end_at' => Carbon::now()->subDays(5)->setTime(12, 30),
                'origin_country' => 'ES',
                'origin_location_id' => $locations['madrid_office']->id,
                'destination_country' => 'FR',
                'destination_location_id' => $locations['paris_office']->id,
                'status' => 'completed',
                'created_by' => $users['tech3']->id,
                'updated_by' => $users['tech3']->id,
            ]
        );

        // Tech3 - Project-to-project (FR to DE, both different from ES contract)
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech3']->id,
                'project_id' => $projects['erp_migration']->id,
                'travel_date' => Carbon::now()->subDays(3),
            ],
            [
                'start_at' => Carbon::now()->subDays(3)->setTime(7, 45),
                'end_at' => Carbon::now()->subDays(3)->setTime(9, 30),
                'origin_country' => 'FR',
                'origin_location_id' => $locations['paris_office']->id,
                'destination_country' => 'DE',
                'destination_location_id' => $locations['berlin_office']->id,
                'status' => 'completed',
                'created_by' => $users['tech3']->id,
                'updated_by' => $users['tech3']->id,
            ]
        );

        // Tech1 - Planned future travel
        TravelSegment::firstOrCreate(
            [
                'technician_id' => $technicians['tech1']->id,
                'project_id' => $projects['infrastructure']->id,
                'travel_date' => Carbon::now()->addDays(5),
            ],
            [
                'start_at' => Carbon::now()->addDays(5)->setTime(6, 30),
                'end_at' => Carbon::now()->addDays(5)->setTime(9, 0),
                'origin_country' => 'PT',
                'origin_location_id' => $locations['lisbon_office']->id,
                'destination_country' => 'FR',
                'destination_location_id' => $locations['paris_office']->id,
                'status' => 'planned',
                'created_by' => $users['tech1']->id,
                'updated_by' => $users['tech1']->id,
            ]
        );

        $this->command->info('✅ Travel segments created');
    }
}
