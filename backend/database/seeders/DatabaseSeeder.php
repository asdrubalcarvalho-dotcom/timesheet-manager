<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Create demo users for authentication testing
        User::query()->updateOrCreate(
            ['email' => 'joao.silva@example.com'],
            [
                'name' => 'João Silva',
                'role' => 'Technician',
                'password' => bcrypt('password'),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'carlos.manager@example.com'],
            [
                'name' => 'Carlos Manager',
                'role' => 'Manager',
                'password' => bcrypt('password'),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@timeperk.com'],
            [
                'name' => 'System Administrator',
                'role' => 'Admin',
                'password' => bcrypt('admin123'),
            ]
        );

        $this->call([
            LocationSeeder::class,
            DemoSeeder::class,
            TaskSeeder::class,
            ProjectMemberSeeder::class,
            TechnicianUserLinkerSeeder::class,
            DemoTenantSeeder::class,
            RolesAndPermissionsSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
