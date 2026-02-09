<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TenantTestCase extends TestCase
{
    protected static bool $centralMigrated = false;

    protected static bool $tenantMigrated = false;

    protected static ?Tenant $sharedTenant = null;

    protected static ?string $sharedTenantDatabase = null;

    protected Tenant $tenant;

    private function debug(string $message): void
    {
        if (!filter_var(env('TEST_DEBUG_TENANT', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        fwrite(STDERR, '[TenantTestCase] ' . $message . "\n");
        fflush(STDERR);
    }

    private function cleanTenantDatabase(): void
    {
        $connection = DB::connection('tenant');
        $dbName = (string) config('database.connections.tenant.database');

        if ($dbName === '') {
            $this->debug('cleanTenantDatabase:skip (no database configured)');
            return;
        }

        $this->debug('cleanTenantDatabase:start ' . $dbName);

        // NOTE: We intentionally do NOT use per-test transactions here.
        // Several request middlewares call DB::purge()/reconnect() for the tenant connection,
        // which creates a new PDO connection and cannot see uncommitted rows.
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');

        $rows = $connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');

        foreach ($rows as $row) {
            $values = array_values((array) $row);
            $table = (string) ($values[0] ?? '');

            if ($table === '' || $table === 'migrations') {
                continue;
            }

            $connection->statement('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
        }

        $connection->statement('SET FOREIGN_KEY_CHECKS=1');

        $this->debug('cleanTenantDatabase:done');
    }

    protected function setUp(): void
    {
        $this->debug('setUp:start');
        parent::setUp();
        $this->debug('setUp:after-parent');
        $this->debug('setUp:env app=' . app()->environment() . ' config(app.env)=' . (string) config('app.env'));

        // Tests run without needing tenant asset route registration.
        // Prevent FilesystemTenancyBootstrapper from calling route('stancl.tenancy.asset') too early.
        Config::set('tenancy.filesystem.asset_helper_tenancy', false);
        $this->debug('setUp:after-disable-asset-helper');

        // Run migrations once per PHP process.
        // NOTE: `migrate:fresh` can hang on MySQL when metadata locks exist ("Dropping all tables").
        // We rely on per-test transactions for isolation instead of wiping tables.
        if (!static::$centralMigrated) {
            $this->debug('setUp:migrate:start');
            Artisan::call('migrate', ['--force' => true]);
            $this->debug('setUp:migrate:done');
            static::$centralMigrated = true;
        }

        if (static::$sharedTenant && !Tenant::query()->whereKey(static::$sharedTenant->id)->exists()) {
            $this->debug('setUp:shared-tenant:missing-reset');
            static::$sharedTenant = null;
            static::$sharedTenantDatabase = null;
            static::$tenantMigrated = false;
        }

        if (!static::$sharedTenant) {
            $slug = 'phpunit-' . substr((string) Str::uuid(), 0, 8);

            $this->debug('setUp:create-tenant:start');

            // Avoid model events during tests.
            // In this project, tenant model events can trigger provisioning side-effects that may hang.
            static::$sharedTenant = Tenant::withoutEvents(fn () => Tenant::create([
                'id' => (string) Str::ulid(),
                'name' => 'PHPUnit Tenant',
                'slug' => $slug,
                'owner_email' => 'owner@example.com',
                'status' => 'active',
                'plan' => 'standard',
                'timezone' => config('app.timezone', 'UTC'),
            ]));

            // Ensure tenancy internal DB fields exist even without events.
            // IMPORTANT: This project uses Stancl VirtualColumn, which overwrites the raw `data` column
            // during model saves. Use setInternal() so the virtual attributes are encoded correctly.
            static::$sharedTenant->setInternal('db_name', 'timesheet_' . (string) static::$sharedTenant->id);
            static::$sharedTenant->setInternal('db_driver', 'mysql');
            static::$sharedTenant->setInternal('db_host', config('database.connections.tenant.host'));
            static::$sharedTenant->setInternal('db_port', config('database.connections.tenant.port'));
            static::$sharedTenant->setInternal('db_username', config('database.connections.tenant.username'));
            static::$sharedTenant->setInternal('db_password', config('database.connections.tenant.password'));
            static::$sharedTenant->saveQuietly();

            $this->debug('setUp:create-tenant:done');
        }

        $this->tenant = static::$sharedTenant;

        $this->debug('setUp:tenancy-initialize:start');

        tenancy()->initialize($this->tenant);

        $this->debug('setUp:tenancy-initialize:done');

        // Some bootstrappers can mutate tenant internals during initialization.
        // For tests we always force a dedicated tenant database (never central).
        $centralDbName = (string) config('database.connections.mysql.database');
        $dbName = (string) ($this->tenant->getInternal('db_name') ?? '');

        if ($dbName === '' || $dbName === $centralDbName) {
            $dbName = 'timesheet_' . (string) $this->tenant->id;
            $this->tenant->setInternal('db_name', $dbName);
            $this->tenant->saveQuietly();
        }

        if (!static::$sharedTenantDatabase) {
            static::$sharedTenantDatabase = (string) $dbName;
        }

        // Ensure tenant database exists.
        // We avoid `migrate:fresh` (can hang on MySQL metadata locks) and instead create a dedicated DB.
        $this->debug('setUp:tenant-db:create-if-missing ' . static::$sharedTenantDatabase);
        DB::connection('mysql')->statement(
            'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', static::$sharedTenantDatabase) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        config([
            // If TENANT_DB_URL is set, it can override host/user/pass/database and cause connection issues.
            'database.connections.tenant.url' => null,
            'database.connections.tenant.database' => static::$sharedTenantDatabase,
        ]);

        $this->debug('setUp:tenant-config database=' . (string) config('database.connections.tenant.database') . ' url=' . var_export(config('database.connections.tenant.url'), true));
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('sanctum.connection', 'tenant');

        // Make tenant the default connection for the remainder of the test.
        // This mirrors the runtime middleware behavior and ensures models/factories write to the tenant DB.
        DB::setDefaultConnection('tenant');
        config(['database.default' => 'tenant']);

        $this->debug('setUp:tenant-connection:ready');

        if (!static::$tenantMigrated) {
            $this->debug('setUp:tenant-migrate:start');
            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);
            $this->debug('setUp:tenant-migrate:done');
            static::$tenantMigrated = true;
        }

        $this->cleanTenantDatabase();
    }

    protected function tearDown(): void
    {
        $this->debug('tearDown:start');

        tenancy()->end();

        // Restore the default connection back to central for safety.
        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        $this->debug('tearDown:after-tenancy-end');

        parent::tearDown();
        $this->debug('tearDown:after-parent');
    }

    protected function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenant->slug];
    }
}
