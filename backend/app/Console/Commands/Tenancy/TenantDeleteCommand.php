<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🧹 TenantDeleteCommand
 * ------------------------------------------------------------
 * Safely removes a tenant and its database.
 * 
 * Usage:
 *   php artisan tenants:delete {slug} [--force]
 * 
 * Actions:
 *   - Deletes tenant record from central database
 *   - Drops tenant database (triggers TenantDeleted event)
 *   - Removes associated domains and company records
 */
class TenantDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:delete 
                            {slug : The tenant slug to delete}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a tenant and its database permanently';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $force = $this->option('force');

        // Find tenant
        $tenant = Tenant::where('slug', $slug)->first();
        
        if (!$tenant) {
            $this->error("❌ Tenant '{$slug}' not found");
            return Command::FAILURE;
        }

        $dbPrefix = config('tenancy.database.prefix', 'timesheet_');
        $tenantDbName = $dbPrefix . $tenant->id;

        // Show tenant information
        $this->newLine();
        $this->warn('⚠️  You are about to PERMANENTLY DELETE:');
        $this->newLine();
        $this->components->twoColumnDetail('Tenant ID', $tenant->id);
        $this->components->twoColumnDetail('Slug', $tenant->slug);
        $this->components->twoColumnDetail('Name', $tenant->name);
        $this->components->twoColumnDetail('Database', $tenantDbName);
        $this->components->twoColumnDetail('Owner Email', $tenant->owner_email);
        $this->components->twoColumnDetail('Created', $tenant->created_at->format('Y-m-d H:i:s'));
        $this->newLine();

        // Confirmation
        if (!$force) {
            $confirmed = $this->confirm(
                'This action cannot be undone. Are you sure you want to delete this tenant?',
                false
            );

            if (!$confirmed) {
                $this->info('❎ Operation cancelled');
                return Command::SUCCESS;
            }

            // Double confirmation for extra safety
            $typedSlug = $this->ask("Type the tenant slug '{$slug}' to confirm deletion");
            
            if ($typedSlug !== $slug) {
                $this->error('❌ Slug mismatch. Operation cancelled for safety.');
                return Command::FAILURE;
            }
        }

        $this->newLine();
        $this->info('🚀 Starting tenant deletion...');

        try {
            // Delete tenant (this triggers TenantDeleted event which drops the database)
            $tenant->delete();

            $this->newLine();
            $this->components->info('✅ Tenant record deleted from central database');

            // Verify database was dropped (TenantDeleted event should handle this)
            try {
                $databases = DB::select("SHOW DATABASES LIKE '{$tenantDbName}'");
                
                if (empty($databases)) {
                    $this->components->info("✅ Tenant database '{$tenantDbName}' removed");
                } else {
                    $this->warn("⚠️  Database '{$tenantDbName}' still exists. Attempting manual cleanup...");
                    
                    // Manual cleanup if event didn't fire
                    DB::statement("DROP DATABASE IF EXISTS {$tenantDbName}");
                    $this->components->info("✅ Database manually removed");
                }
            } catch (\Exception $e) {
                $this->warn("⚠️  Could not verify database deletion: " . $e->getMessage());
            }

            $this->newLine();
            $this->info("✅ Tenant '{$slug}' successfully deleted!");
            
            // Log the deletion
            \Log::info("Tenant deleted via CLI", [
                'slug' => $slug,
                'tenant_id' => $tenant->id,
                'database' => $tenantDbName,
                'deleted_by' => 'artisan_command',
                'timestamp' => now()->toISOString(),
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ Failed to delete tenant: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            
            \Log::error('Tenant deletion failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
