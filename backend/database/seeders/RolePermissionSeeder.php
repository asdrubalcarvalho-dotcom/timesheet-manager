<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Define roles (Owner is the super admin of the tenant)
        $roles = [
            'Owner',      // Super Admin - created during tenant registration, has ALL permissions
            'Admin',      // Full access except finance (configurable)
            'Manager',    // Project/team management
            'Technician', // Base user
            'Viewer'      // Read-only
        ];

        // Define permissions
        $permissions = [
            'view timesheets',
            'approve timesheets',
            'edit timesheets',
            'delete timesheets',
            'view expenses',
            'approve expenses',
            'edit expenses',
            'delete expenses',
            'manage users',
            'manage projects',
            'manage resources',
            'manage locations',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        foreach ($roles as $role) {
            $roleModel = Role::firstOrCreate(['name' => $role]);
            
            if ($role === 'Owner') {
                // Owner has ALL permissions (including future ones)
                $roleModel->givePermissionTo($permissions);
            } elseif ($role === 'Admin') {
                // Admin has all permissions EXCEPT finance-related (must be assigned manually)
                $nonFinancePermissions = array_filter($permissions, function($perm) {
                    return !str_contains($perm, 'finance');
                });
                $roleModel->givePermissionTo($nonFinancePermissions);
            } elseif ($role === 'Manager') {
                $roleModel->givePermissionTo([
                    'view timesheets', 'approve timesheets', 'edit timesheets',
                    'view expenses', 'approve expenses', 'edit expenses',
                    'manage projects', 'manage resources', 'manage locations'
                ]);
            } elseif ($role === 'Technician') {
                $roleModel->givePermissionTo([
                    'view timesheets', 'edit timesheets',
                    'view expenses', 'edit expenses'
                ]);
            } elseif ($role === 'Viewer') {
                $roleModel->givePermissionTo(['view timesheets', 'view expenses']);
            }
        }

        // DO NOT assign role to first user - Owner is created during tenant registration
    }
}
