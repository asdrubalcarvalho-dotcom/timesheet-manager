<?php

namespace Database\Seeders;

use App\Models\Technician;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed an admin user for system administration.
     */
    public function run(): void
    {
        // Create admin user
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@timeperk.com'],
            [
                'name' => 'System Administrator',
                'password' => Hash::make('admin123'),
                'role' => 'Admin'
            ]
        );

        $tenant = Tenant::where('slug', 'demo')->first();

        if ($tenant && $adminUser->tenant_id !== $tenant->id) {
            $adminUser->tenant_id = $tenant->id;
            $adminUser->save();
        }

        // Assign Admin role if using Spatie
        if ($tenant && !$adminUser->hasRole('Admin')) {
            $registrar = app(PermissionRegistrar::class);
            $registrar->setPermissionsTeamId($tenant->id);

            $adminUser->assignRole('Admin');

            $registrar->setPermissionsTeamId(null);
        }

        // Create corresponding technician record
        Technician::firstOrCreate(
            ['email' => 'admin@timeperk.com'],
            [
                'name' => 'System Administrator',
                'role' => 'manager', // Using manager role for admin user
                'hourly_rate' => 100.00,
                'is_active' => true,
                'user_id' => $adminUser->id,
                'tenant_id' => $tenant?->id,
                'worker_id' => sprintf('SYS-%04d', $adminUser->id),
                'worker_name' => 'System Administrator',
            ]
        );

        $this->command->info('Admin user created successfully!');
        $this->command->info('Email: admin@timeperk.com');
        $this->command->info('Password: admin123');
        $this->command->warn('⚠️  Please change the default password in production!');
    }
}
