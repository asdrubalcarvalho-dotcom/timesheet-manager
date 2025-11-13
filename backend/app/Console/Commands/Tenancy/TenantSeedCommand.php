<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenantSeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:seed 
                            {tenant? : The tenant slug or ID}
                            {--class= : The seeder class to run}
                            {--all : Seed all tenants}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run database seeders for one or all tenants';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->seedAllTenants();
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

        return $this->seedTenant($tenant);
    }

    protected function seedTenant(Tenant $tenant): int
    {
        $this->info("Seeding tenant: {$tenant->slug} ({$tenant->name})");

        try {
            $tenant->run(function () {
                $class = $this->option('class') ?: 'Database\\Seeders\\TenantDatabaseSeeder';
                
                Artisan::call('db:seed', [
                    '--class' => $class,
                    '--force' => true,
                ]);

                $output = Artisan::output();
                $this->line($output);
            });

            $this->info("✓ Seeded {$tenant->slug} successfully");
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Failed to seed {$tenant->slug}: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    protected function seedAllTenants(): int
    {
        $tenants = Tenant::where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');
            return Command::SUCCESS;
        }

        $this->info("Seeding {$tenants->count()} tenants...");
        $bar = $this->output->createProgressBar($tenants->count());

        $failed = [];

        foreach ($tenants as $tenant) {
            try {
                $this->seedTenant($tenant);
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

        $this->info('All tenants seeded successfully');
        return Command::SUCCESS;
    }
}
