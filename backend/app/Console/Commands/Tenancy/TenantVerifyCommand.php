<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔍 TenantVerifyCommand
 * ------------------------------------------------------------
 * Validates tenant integrity across central and tenant databases.
 * 
 * Usage:
 *   php artisan tenants:verify {slug}
 * 
 * Checks:
 *   - Tenant exists in central database
 *   - Tenant database physically exists
 *   - Required tables are present
 *   - Admin user exists with proper role
 */
class TenantVerifyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:verify 
                            {slug : The tenant slug to verify}
                            {--detailed : Show detailed table and user information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify tenant integrity (central record, database, tables, admin user)';

    /**
     * Required tables that should exist in every tenant database.
     *
     * @var array<string>
     */
    protected array $requiredTables = [
        'users',
        'projects',
        'timesheets',
        'expenses',
        'tasks',
        'locations',
        'technicians',
        'project_members',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $detailed = $this->option('detailed');

        $this->info("🔍 Verifying tenant: {$slug}");
        $this->newLine();

        // Step 1: Check central database record
        $tenant = Tenant::where('slug', $slug)->first();
        
        if (!$tenant) {
            $this->error("❌ Tenant '{$slug}' not found in central database");
            $this->info("💡 Hint: Check 'tenants' table or use 'php artisan tenants:list'");
            return Command::FAILURE;
        }

        $this->components->twoColumnDetail('✅ Central Record', 'Found');
        $this->components->twoColumnDetail('   Tenant ID', $tenant->id);
        $this->components->twoColumnDetail('   Name', $tenant->name);
        $this->components->twoColumnDetail('   Status', $tenant->status);
        $this->components->twoColumnDetail('   Owner Email', $tenant->owner_email);
        $this->components->twoColumnDetail('   Created', $tenant->created_at->diffForHumans());
        $this->newLine();

        // Step 2: Check tenant database existence
        $dbPrefix = config('tenancy.database.prefix', 'timesheet_');
        $tenantDbName = $dbPrefix . $tenant->id;
        
        try {
            $databases = DB::select("SHOW DATABASES LIKE '{$tenantDbName}'");
            
            if (empty($databases)) {
                $this->error("❌ Tenant database '{$tenantDbName}' does not exist");
                $this->info("💡 Hint: Run 'php artisan tenants:migrate {$slug}' to create it");
                return Command::FAILURE;
            }

            $this->components->twoColumnDetail('✅ Database', $tenantDbName);
        } catch (\Exception $e) {
            $this->error("❌ Failed to check database: " . $e->getMessage());
            return Command::FAILURE;
        }

        // Step 3: Check tables within tenant context
        $this->newLine();
        $this->info('🗄️  Checking tenant database tables...');
        
        $missingTables = [];
        $presentTables = [];

        try {
            $tenant->run(function () use (&$missingTables, &$presentTables, $detailed) {
                foreach ($this->requiredTables as $table) {
                    if (Schema::hasTable($table)) {
                        $presentTables[] = $table;
                        
                        if ($detailed) {
                            $count = DB::table($table)->count();
                            $this->components->twoColumnDetail("   ✓ {$table}", "{$count} records");
                        }
                    } else {
                        $missingTables[] = $table;
                    }
                }
            });
        } catch (\Exception $e) {
            $this->error("❌ Failed to access tenant database: " . $e->getMessage());
            return Command::FAILURE;
        }

        if (!$detailed) {
            $this->components->twoColumnDetail('✅ Tables Present', count($presentTables) . '/' . count($this->requiredTables));
        }

        if (!empty($missingTables)) {
            $this->newLine();
            $this->warn('⚠️  Missing tables:');
            foreach ($missingTables as $table) {
                $this->line("   - {$table}");
            }
            $this->info("💡 Hint: Run 'php artisan tenants:migrate {$slug}' to create missing tables");
        }

        // Step 4: Check admin user
        $this->newLine();
        $this->info('👤 Checking admin user...');
        
        try {
            $adminExists = false;
            $adminEmail = null;
            $adminRoles = [];

            $tenant->run(function () use (&$adminExists, &$adminEmail, &$adminRoles, $detailed) {
                $admin = User::whereHas('roles', function ($query) {
                    $query->where('name', 'Admin');
                })->first();

                if ($admin) {
                    $adminExists = true;
                    $adminEmail = $admin->email;
                    $adminRoles = $admin->roles->pluck('name')->toArray();
                }
            });

            if ($adminExists) {
                $this->components->twoColumnDetail('✅ Admin User', 'Found');
                $this->components->twoColumnDetail('   Email', $adminEmail);
                if ($detailed) {
                    $this->components->twoColumnDetail('   Roles', implode(', ', $adminRoles));
                }
            } else {
                $this->warn('⚠️  No admin user found in tenant database');
                $this->info("💡 Hint: Run tenant seeder to create admin user");
            }
        } catch (\Exception $e) {
            $this->error("❌ Failed to check admin user: " . $e->getMessage());
        }

        // Step 5: Check domains
        $this->newLine();
        $this->info('🌐 Checking domains...');
        
        $domains = $tenant->domains()->get();
        
        if ($domains->isEmpty()) {
            $this->warn('⚠️  No domains configured for this tenant');
        } else {
            $this->components->twoColumnDetail('✅ Domains', $domains->count());
            if ($detailed) {
                foreach ($domains as $domain) {
                    $this->components->twoColumnDetail("   → {$domain->domain}", '');
                }
            }
        }

        // Final summary
        $this->newLine();
        $this->newLine();
        
        if (empty($missingTables) && $adminExists) {
            $this->info("✅ Tenant '{$slug}' is fully operational!");
        } else {
            $this->warn("⚠️  Tenant '{$slug}' has some issues (see above)");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
