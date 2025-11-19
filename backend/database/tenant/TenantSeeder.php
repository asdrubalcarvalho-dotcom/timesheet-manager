<?php

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        // Lista de permissões
        $permissions = [
            'view-timesheets', 'create-timesheets', 'edit-timesheets', 'delete-timesheets',
            'view-expenses', 'create-expenses', 'edit-expenses', 'delete-expenses',
            'view-projects', 'manage-projects',
            'view-tasks', 'manage-tasks',
            'view-technicians', 'manage-technicians',
            'view-locations', 'manage-locations',
            'view-travels', 'manage-travels',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }

        // Criar roles
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web']);
        $manager = Role::firstOrCreate(['name' => 'Manager', 'guard_name' => 'web']);
        $tech = Role::firstOrCreate(['name' => 'Technician', 'guard_name' => 'web']);

        // Owner recebe todas
        $owner->syncPermissions(Permission::all());

        // Atribuir Owner ao 1º utilizador
        $admin = \App\Models\User::first();
        if ($admin) {
            $admin->assignRole('Owner');
        }
    }
}
