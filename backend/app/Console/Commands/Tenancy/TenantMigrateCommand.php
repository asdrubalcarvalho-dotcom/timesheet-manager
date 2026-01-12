<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class TenantMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate 
                            {tenant? : The tenant slug or ID}
                            {--all : Migrate all tenants}
                            {--fresh : Drop all tables and re-run migrations}
                            {--seed : Seed after migration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for one or all tenant databases';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->migrateAllTenants();
        }

        $tenantIdentifier = $this->argument('tenant');

        if (!$tenantIdentifier) {
            $this->error('Please provide a tenant slug/ID or use --all flag');
            return Command::FAILURE;
        }

        $tenant = Tenant::where('slug', $tenantIdentifier)
            ->orWhere('id', $tenantIdentifier)
            ->first();

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantIdentifier}");
            return Command::FAILURE;
        }

        return $this->migrateTenant($tenant);
    }

    protected function migrateTenant(Tenant $tenant): int
    {
        $this->info("Migrating tenant: {$tenant->slug} ({$tenant->name})");

        try {
            $tenant->run(function () use ($tenant) {
                $previousDefaultConnection = DB::getDefaultConnection();

                // DatabaseTenancyBootstrapper is disabled in this project.
                // For CLI tenant migrations we must manually point the tenant
                // connection at the tenant database.
                $databaseName = $tenant->getInternal('db_name');
                if (empty($databaseName)) {
                    throw new \RuntimeException('Tenant database name (internal db_name) is missing.');
                }

                Config::set('database.connections.tenant.database', $databaseName);
                DB::purge('tenant');
                DB::reconnect('tenant');
                DB::setDefaultConnection('tenant');
                Config::set('database.default', 'tenant');

                $command = $this->option('fresh') ? 'migrate:fresh' : 'migrate';
                
                try {
                    Artisan::call($command, [
                        '--force' => true,
                        '--path' => 'database/migrations/tenant',
                    ]);

                    $output = Artisan::output();
                    $this->line($output);

                    if ($this->option('seed')) {
                        Artisan::call('db:seed', [
                            '--class' => 'Database\\Seeders\\TenantDatabaseSeeder',
                            '--force' => true,
                        ]);
                        $this->info('✓ Seeded tenant database');
                    }
                } finally {
                    // Restore default connection for subsequent operations.
                    DB::setDefaultConnection($previousDefaultConnection);
                    Config::set('database.default', $previousDefaultConnection);
                }
            });

            $this->info("✓ Migrated {$tenant->slug} successfully");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to migrate {$tenant->slug}: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function migrateAllTenants(): int
    {
        $tenants = Tenant::where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Migrating {$tenants->count()} tenants...");
        $bar = $this->output->createProgressBar($tenants->count());

        $failed = [];

        foreach ($tenants as $tenant) {
            try {
                $this->migrateTenant($tenant);
            } catch (\Exception $e) {
                $failed[] = $tenant->slug;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (!empty($failed)) {
            $this->error('Failed tenants: ' . implode(', ', $failed));
            return Command::FAILURE;
        }

        $this->info('All tenants migrated successfully');
        return Command::SUCCESS;
    }
}
