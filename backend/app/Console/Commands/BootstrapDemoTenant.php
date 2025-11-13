<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Access\TenantRoleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class BootstrapDemoTenant extends Command
{
    protected $signature = 'tenancy:bootstrap-demo
        {--slug=demo : Slug (and identifier) for the tenant}
        {--tenant-name=Demo Tenant : Friendly tenant name}
        {--tenant-email=admin@example.com : Owner email to store on tenant}
        {--company-name=Demo Company : Company display name}
        {--company-country=US : Company country (ISO 3166-1 alpha-2)}
        {--admin-name=Demo Admin : Name for the bootstrap admin user}
        {--admin-email=admin@example.com : Email for the bootstrap admin user}
        {--admin-password=password : Password for the bootstrap admin user}';

    protected $description = 'Create a demo tenant + company and backfill tenant_id across core tables.';

    public function __construct(
        protected TenantRoleManager $roleManager,
        protected PermissionRegistrar $permissionRegistrar
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $slug = Str::slug((string) $this->option('slug'));
        $tenantName = (string) $this->option('tenant-name');
        $tenantEmail = (string) $this->option('tenant-email');
        $companyName = (string) $this->option('company-name');
        $companyCountry = strtoupper((string) $this->option('company-country'));
        $adminName = (string) $this->option('admin-name');
        $adminEmail = strtolower((string) $this->option('admin-email'));
        $adminPassword = (string) $this->option('admin-password');

        if ($slug === '') {
            $this->error('Tenant slug cannot be empty.');
            return self::INVALID;
        }

        DB::transaction(function () use (
            $slug,
            $tenantName,
            $tenantEmail,
            $companyName,
            $companyCountry,
            $adminName,
            $adminEmail,
            $adminPassword
        ) {
            $tenant = Tenant::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $tenantName,
                    'owner_email' => $tenantEmail,
                    'status' => 'active',
                    'plan' => 'standard',
                    'timezone' => config('app.timezone', 'UTC'),
                    'settings' => ['source' => 'bootstrap-command'],
                ]
            );

            $company = Company::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'name' => $companyName,
                    'legal_name' => $companyName,
                    'country' => $companyCountry,
                    'timezone' => config('app.timezone', 'UTC'),
                    'status' => 'active',
                    'billing_email' => $tenantEmail,
                    'support_email' => $tenantEmail,
                ]
            );

            $admin = User::firstOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => Hash::make($adminPassword),
                    'role' => 'Admin',
                ]
            );

            if ($admin->tenant_id !== $tenant->id) {
                $admin->tenant_id = $tenant->id;
                $admin->save();
            }

            $this->seedTenantRoles($tenant);
            $this->assignAdminRole($admin, $tenant);

            $this->info(sprintf('Tenant "%s" ready (ID: %s)', $tenant->name, $tenant->id));
            $this->info(sprintf('Company "%s" synced (ID: %s)', $company->name, $company->id));
            $this->info(sprintf('Admin user available at %s (password: %s)', $admin->email, $adminPassword));

            $tables = [
                'users',
                'projects',
                'timesheets',
                'expenses',
                'tasks',
                'locations',
                'technicians',
                'project_members',
            ];

            $timestamp = now();

            foreach ($tables as $table) {
                if (!Schema::hasColumn($table, 'tenant_id')) {
                    $this->warn(sprintf('Skipped %s — tenant_id column not found.', $table));
                    continue;
                }

                $updated = DB::table($table)
                    ->whereNull('tenant_id')
                    ->update([
                        'tenant_id' => $tenant->id,
                        'updated_at' => $timestamp,
                    ]);

                if ($updated > 0) {
                    $this->info(sprintf('Backfilled %d %s record(s).', $updated, $table));
                }
            }
        });

        return self::SUCCESS;
    }

    protected function seedTenantRoles(Tenant $tenant): void
    {
        $this->roleManager->ensurePermissions();
        $this->roleManager->syncTenantRoles($tenant);
    }

    protected function assignAdminRole(User $admin, Tenant $tenant): void
    {
        if (! method_exists($admin, 'syncRoles')) {
            return;
        }

        try {
            $this->permissionRegistrar->setPermissionsTeamId($tenant->id);
            $admin->syncRoles(['Admin']);
        } catch (Throwable $exception) {
            $this->warn('Could not assign Admin role: ' . $exception->getMessage());
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId(null);
        }
    }
}
