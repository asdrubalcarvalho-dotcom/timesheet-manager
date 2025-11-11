<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Technician;
use Illuminate\Support\Facades\Hash;

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

        // Assign Admin role if using Spatie
        if (!$adminUser->hasRole('Admin')) {
            $adminUser->assignRole('Admin');
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
