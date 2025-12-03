<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetupDatabasePermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:setup-permissions 
                            {--force : Force setup even if permissions seem correct}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup necessary database permissions for multi-tenancy (CREATE privilege + tenant DB access)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔐 Checking database permissions for multi-tenancy...');
        $this->newLine();

        try {
            // Get database credentials from config
            $username = config('database.connections.mysql.username');
            $host = '%'; // Default to wildcard host

            // Check current grants
            $this->info("Checking grants for user: {$username}@{$host}");
            
            $grants = $this->getCurrentGrants($username, $host);
            
            if (empty($grants)) {
                $this->error("❌ Could not retrieve grants. Make sure you have root/admin access.");
                return 1;
            }

            $this->info("Current grants:");
            foreach ($grants as $grant) {
                $this->line("  • " . $grant->Grant);
            }
            $this->newLine();

            // Check if necessary permissions exist
            $hasCreate = $this->hasCreatePrivilege($grants);
            $hasTenantAccess = $this->hasTenantDatabaseAccess($grants);

            if ($hasCreate && $hasTenantAccess && !$this->option('force')) {
                $this->info("✅ All necessary permissions are already configured!");
                return 0;
            }

            // Apply missing permissions
            $this->warn("⚠️  Missing permissions detected. Attempting to fix...");
            $this->newLine();

            if (!$hasCreate || $this->option('force')) {
                $this->grantCreatePrivilege($username, $host);
            }

            if (!$hasTenantAccess || $this->option('force')) {
                $this->grantTenantDatabaseAccess($username, $host);
            }

            // Flush privileges
            DB::connection('mysql')->getPdo()->exec('FLUSH PRIVILEGES');
            
            $this->newLine();
            $this->info("✅ Database permissions configured successfully!");
            $this->newLine();
            
            $this->info("Permissions summary:");
            $this->line("  ✓ CREATE privilege (for creating tenant databases)");
            $this->line("  ✓ ALL privileges on timesheet_% pattern (tenant databases)");
            $this->newLine();

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Failed to setup permissions: " . $e->getMessage());
            $this->newLine();
            $this->warn("💡 Tip: This command requires root/admin MySQL access.");
            $this->warn("   Run manually with: docker-compose exec database mysql -u root -proot < docker/mysql/init.sql");
            
            return 1;
        }
    }

    /**
     * Get current grants for a user
     */
    private function getCurrentGrants(string $username, string $host): array
    {
        try {
            // Try to get grants using root connection or current connection
            $pdo = DB::connection('mysql')->getPdo();
            $stmt = $pdo->query("SHOW GRANTS FOR '{$username}'@'{$host}'");
            $results = $stmt->fetchAll(\PDO::FETCH_NUM);
            
            // Convert to objects with Grant property
            return array_map(function($row) {
                return (object)['Grant' => $row[0]];
            }, $results);
        } catch (\Exception $e) {
            // If we can't get grants, return empty array
            return [];
        }
    }

    /**
     * Check if user has CREATE privilege
     */
    private function hasCreatePrivilege(array $grants): bool
    {
        foreach ($grants as $grant) {
            $grantText = $grant->Grant;
            if (
                str_contains($grantText, 'GRANT ALL PRIVILEGES ON *.*') ||
                str_contains($grantText, 'GRANT CREATE ON *.*')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user has access to tenant databases
     */
    private function hasTenantDatabaseAccess(array $grants): bool
    {
        foreach ($grants as $grant) {
            $grantText = $grant->Grant;
            if (
                str_contains($grantText, '`timesheet_%`') ||
                str_contains($grantText, 'GRANT ALL PRIVILEGES ON *.*')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Grant CREATE privilege
     */
    private function grantCreatePrivilege(string $username, string $host): void
    {
        $this->line("  → Granting CREATE privilege...");
        $pdo = DB::connection('mysql')->getPdo();
        $pdo->exec("GRANT CREATE ON *.* TO '{$username}'@'{$host}'");
        $this->info("  ✓ CREATE privilege granted");
    }

    /**
     * Grant access to tenant databases
     */
    private function grantTenantDatabaseAccess(string $username, string $host): void
    {
        $this->line("  → Granting access to tenant databases (timesheet_% pattern)...");
        $pdo = DB::connection('mysql')->getPdo();
        $pdo->exec("GRANT ALL PRIVILEGES ON `timesheet_%`.* TO '{$username}'@'{$host}'");
        $this->info("  ✓ Tenant database access granted");
    }
}
