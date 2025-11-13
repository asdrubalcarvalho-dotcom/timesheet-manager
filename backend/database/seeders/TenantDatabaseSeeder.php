<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds for a tenant.
     * This seeder runs within tenant context (tenant database).
     */
    public function run(): void
    {
        // Seed roles and permissions for this tenant
        $this->call([
            RolePermissionSeeder::class,
        ]);

        // Optional: Seed demo data if in development
        if (app()->environment(['local', 'development', 'staging'])) {
            $this->call([
                // Add demo seeders here if needed
                // DemoSeeder::class,
            ]);
        }
    }
}
