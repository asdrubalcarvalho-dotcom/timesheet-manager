<?php

namespace App\Console\Commands\Tenancy;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantBaselineMigrationsCommand extends Command
{
    protected $signature = 'tenant:baseline-migrations
                            {slug : The tenant slug or ID}
                            {--batch=1 : Batch number to record}
                            {--dry-run : Show missing migrations without inserting}';

    protected $description = 'Mark tenant migrations as executed based on existing schema without rerunning them';

    public function handle(Filesystem $filesystem): int
    {
        $tenantIdentifier = $this->argument('slug');
        $batch = max(1, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantIdentifier)
            ->orWhere('id', $tenantIdentifier)
            ->first();

        if (!$tenant) {
            $this->error("Tenant not found: {$tenantIdentifier}");
            return Command::FAILURE;
        }

        $paths = $this->getMigrationPaths();
        $migrations = $this->collectMigrations($filesystem, $paths);

        if (empty($migrations)) {
            $this->warn('No tenant migration files found.');
            return Command::SUCCESS;
        }

        $this->info("Tenant: {$tenant->slug} ({$tenant->id})");
        $this->line('Paths: ' . implode(', ', $paths));
        $this->line('Migration files found: ' . count($migrations));

        $tenant->run(function () use ($migrations, $batch, $dryRun) {
            if (!Schema::hasTable('migrations')) {
                Schema::create('migrations', function (Blueprint $table) {
                    $table->increments('id');
                    $table->string('migration');
                    $table->integer('batch');
                });
                $this->info('Created migrations table in tenant database.');
            }

            $existing = DB::table('migrations')->pluck('migration')->all();
            $missing = array_values(array_diff($migrations, $existing));
            sort($missing);

            $this->line('Already present: ' . count($existing));
            $this->line('Missing: ' . count($missing));

            if ($dryRun) {
                if (!empty($missing)) {
                    $this->line('Missing list (dry-run):');
                    foreach ($missing as $name) {
                        $this->line(" - {$name}");
                    }
                }
                return;
            }

            if (empty($missing)) {
                $this->info('Nothing to baseline.');
                return;
            }

            $rows = array_map(fn (string $name) => [
                'migration' => $name,
                'batch' => $batch,
            ], $missing);

            DB::table('migrations')->insert($rows);
            $this->info('Inserted: ' . count($rows) . ' migration records.');
        });

        return Command::SUCCESS;
    }

    private function getMigrationPaths(): array
    {
        $paths = config('tenancy.migration_parameters.--path') ?? [database_path('migrations/tenant')];
        $paths = is_array($paths) ? $paths : [$paths];

        return array_values(array_filter(array_map(static function ($path) {
            $resolved = realpath($path);
            return $resolved ?: $path;
        }, $paths)));
    }

    private function collectMigrations(Filesystem $filesystem, array $paths): array
    {
        $migrations = [];

        foreach ($paths as $path) {
            if (!$filesystem->isDirectory($path)) {
                $this->warn("Path not found or not a directory: {$path}");
                continue;
            }

            foreach ($filesystem->files($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $migrations[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            }
        }

        sort($migrations);

        return array_values(array_unique($migrations));
    }
}
