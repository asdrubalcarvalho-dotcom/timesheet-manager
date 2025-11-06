<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Timesheet permissions
            'view-timesheets',
            'create-timesheets',
            'edit-own-timesheets',
            'edit-all-timesheets',
            'approve-timesheets',
            'delete-timesheets',
            
            // Expense permissions  
            'view-expenses',
            'create-expenses',
            'edit-own-expenses',
            'edit-all-expenses',
            'approve-expenses',
            'delete-expenses',
            
            // Management permissions
            'view-reports',
            'manage-users',
            'manage-projects',
            'manage-tasks',
            'manage-locations',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions
        
        // Technician role (basic user)
        $technicianRole = Role::create(['name' => 'Technician']);
        $technicianRole->givePermissionTo([
            'view-timesheets',
            'create-timesheets', 
            'edit-own-timesheets',
            'view-expenses',
            'create-expenses',
            'edit-own-expenses',
        ]);

        // Manager role (can approve and manage)
        $managerRole = Role::create(['name' => 'Manager']);
        $managerRole->givePermissionTo([
            'view-timesheets',
            'create-timesheets',
            'edit-own-timesheets',
            'edit-all-timesheets',
            'approve-timesheets',
            'view-expenses',
            'create-expenses', 
            'edit-own-expenses',
            'edit-all-expenses',
            'approve-expenses',
            'view-reports',
        ]);

        // Admin role (full access)
        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo(Permission::all());

        // Assign roles to existing users based on their current 'role' field
        $users = User::all();
        foreach ($users as $user) {
            switch ($user->role) {
                case 'Manager':
                    $user->assignRole('Manager');
                    break;
                case 'Admin':
                    $user->assignRole('Admin');
                    break;
                case 'Technician':
                default:
                    $user->assignRole('Technician');
                    break;
            }
        }

        $this->command->info('Roles and permissions seeded successfully!');
        $this->command->info('Roles created: Technician, Manager, Admin');
        $this->command->info('Users updated with appropriate roles based on existing role field');
    }
}
