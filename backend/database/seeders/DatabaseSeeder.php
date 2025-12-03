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
        // Central database seeding only
        // Users are created in tenant databases during tenant registration
        
        $this->command->info('✅ Central database seeded successfully.');
        $this->command->info('💡 To seed tenant data, use: php artisan tenants:seed <tenant-slug>');
    }
}
