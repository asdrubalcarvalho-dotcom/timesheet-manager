<?php

namespace Tests;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected static bool $tenantMigrationsRan = false;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $slug = 'test-' . substr((string) Str::uuid(), 0, 8);
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => $slug,
            'owner_email' => 'owner@example.com',
            'status' => 'active',
            'plan' => 'standard',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        tenancy()->initialize($this->tenant);

        // Mirror the project's request middleware behavior for the tenant connection.
        $databaseName = $this->tenant->getInternal('db_name');
        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('sanctum.connection', 'tenant');

        if (!static::$tenantMigrationsRan) {
            // In tests the tenant database may share the same physical schema as central.
            // Skip the tenant 'create_users_table' migration if the users table already exists.
            DB::connection('tenant')->table('migrations')->updateOrInsert(
                ['migration' => '0001_01_01_000000_create_users_table'],
                ['batch' => 1]
            );

            $exitCode = $this->artisan('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ])->run();

            if ($exitCode !== 0) {
                throw new \RuntimeException('Tenant migrations failed.');
            }

            static::$tenantMigrationsRan = true;
        }
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        parent::tearDown();
    }

    protected function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenant->slug];
    }
}
