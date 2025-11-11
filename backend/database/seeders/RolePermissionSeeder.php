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
        // Define roles
        $roles = [
            'Admin',
            'Manager',
            'Technician',
            'Viewer'
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
            if ($role === 'Admin') {
                $roleModel->givePermissionTo($permissions);
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

        // Optionally assign Admin role to first user
        $user = User::first();
        if ($user) {
            $user->assignRole('Admin');
        }
    }
}
