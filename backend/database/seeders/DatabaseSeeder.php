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
        User::factory()->create([
            'name' => 'João Silva',
            'email' => 'joao.silva@example.com',
            'role' => 'Technician',
            'password' => bcrypt('password'),
        ]);

        User::factory()->create([
            'name' => 'Carlos Manager',
            'email' => 'carlos.manager@example.com',
            'role' => 'Manager',
            'password' => bcrypt('password'),
        ]);

        User::factory()->create([
            'name' => 'System Administrator',
            'email' => 'admin@timeperk.com',
            'role' => 'Admin',
            'password' => bcrypt('admin123'),
        ]);

        $this->call([
            RolesAndPermissionsSeeder::class,
            LocationSeeder::class,
            DemoSeeder::class,
            TaskSeeder::class,
            ProjectMemberSeeder::class,
            TechnicianUserLinkerSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
