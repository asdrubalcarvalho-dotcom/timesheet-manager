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

        // Run IT tasks and professional locations seeders first
        $this->call(TaskSeeder::class);
        $this->call(LocationSeeder::class);
        
        // Run demo data seeder
        $this->call(DemoSeeder::class);
    }
}
