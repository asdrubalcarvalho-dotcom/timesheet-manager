<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Access\TenantRoleManager;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function __construct(
        protected TenantRoleManager $roleManager,
        protected PermissionRegistrar $registrar
    ) {
    }

    public function run(): void
    {
        $this->registrar->forgetCachedPermissions();

        $this->roleManager->ensurePermissions();

        $tenants = Tenant::all();

        if ($tenants->isEmpty()) {
            $this->command?->warn('No tenants found. Run tenancy:bootstrap-demo before seeding roles.');
            return;
        }

        foreach ($tenants as $tenant) {
            $this->roleManager->syncTenantRoles($tenant);
        }

        $this->assignUsersToTenantRoles();

        $this->command?->info('Tenant roles and permissions synced successfully.');
    }

    protected function assignUsersToTenantRoles(): void
    {
        // In multi-database tenancy, users table exists in each tenant database
        // No tenant_id column needed - context is implicit
        User::query()
            ->get()
            ->each(function (User $user): void {
                // No need to set team ID in multi-database tenancy
                // Each tenant has separate database with separate roles

                $roleName = match ($user->role) {
                    'Owner' => 'Owner',
                    'Manager' => 'Manager',
                    'Admin' => 'Admin',
                    default => 'Technician',
                };

                $user->syncRoles([$roleName]);
            });
    }
}
