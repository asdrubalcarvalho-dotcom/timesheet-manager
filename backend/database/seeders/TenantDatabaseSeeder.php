<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;

class TenantDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds for a tenant (runs inside tenant DB).
     */
    public function run(): void
    {
        // DatabaseTenancyBootstrapper is disabled in this project.
        // Ensure all seeding happens on the tenant connection.
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');

        // 1. Criar todas as roles + permissions necessárias no tenant
        $this->call([
            RolesAndPermissionsSeeder::class,
            CountriesSeeder::class,
        ]);

        // 2. Garantir que existe o utilizador Admin (Owner) no tenant
        $admin = User::updateOrCreate(
            ['email' => 'admin@vendaslive.com'],
            [
                'name'              => 'Admin',
                'password'          => bcrypt('12345678'),
                'role'              => 'Owner',
                'email_verified_at' => now(),
            ]
        );

        // 3. Atribuir role Owner e TODAS as permissões a este user
        $admin->assignRole('Owner');

        // Dar todas as permissões existentes
        $admin->givePermissionTo(Permission::all());

        // 4. (Opcional) Demo data em ambientes não-prod
        if (app()->environment(['local', 'development', 'staging'])) {
            $this->call([
                // DemoSeeder::class,
                // CompleteTestDataSeeder::class,
            ]);
        }
    }
}
