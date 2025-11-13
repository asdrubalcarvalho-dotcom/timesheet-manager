<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Models\Tenant;
use Stancl\Tenancy\Facades\Tenancy;

/**
 * 🧪 TenantOnboardingTest
 * ------------------------------------------------------------
 * Validates the full onboarding flow:
 *  - POST /api/tenants/register
 *  - Tenant record created in central DB
 *  - Tenant database (timesheet_<slug>) created
 *  - Migrations and seeders executed
 *  - Admin user + Sanctum token returned
 */
class TenantOnboardingTest extends TestCase
{
    // Note: RefreshDatabase disabled to avoid transaction isolation issues with tenancy
    // Manual cleanup in setUp() and tearDown() instead

    protected string $tenantSlug;
    protected string $tenantDb;

    public function setUp(): void
    {
        parent::setUp();
        
        // Clear residual domains/tenants from previous test runs (disable FK checks first)
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('domains')->truncate();
        DB::table('tenants')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        // Generate unique slug for this test run (lowercase only, no underscores)
        $this->tenantSlug = 'qatest' . now()->timestamp;
        $this->tenantDb = 'timesheet_' . $this->tenantSlug;
    }

    /** @test */
    public function it_registers_a_tenant_and_creates_their_database()
    {
        // 1️⃣ Prepare request payload
        $payload = [
            'company_name' => 'QA Automation Co.',
            'slug' => $this->tenantSlug,
            'admin_name' => 'QA Admin',
            'admin_email' => "admin@{$this->tenantSlug}.test",
            'admin_password' => 'secret123',
            'admin_password_confirmation' => 'secret123',
            'industry' => 'Testing',
            'country' => 'PT',
            'timezone' => 'Europe/Lisbon',
        ];

        // 2️⃣ Send registration request
        $response = $this->postJson('/api/tenants/register', $payload);
        
        if ($response->status() !== 201) {
            dump($response->json());
        }
        
        $response->assertStatus(201)->assertJsonStructure([
            'status',
            'message',
            'tenant',
            'database',
            'tenant_info' => ['id', 'slug', 'name', 'domain', 'status', 'trial_ends_at'],
            'admin' => ['email', 'token'],
            'next_steps' => ['login_url', 'api_header'],
        ]);

        // 3️⃣ Check central DB entry (slug is unique, not id)
        $this->assertDatabaseHas('tenants', ['slug' => $this->tenantSlug]);
        
        // Get created tenant to verify database name
        $tenant = Tenant::where('slug', $this->tenantSlug)->first();
        $this->assertNotNull($tenant, "Tenant with slug {$this->tenantSlug} not found");
        $actualTenantDb = 'timesheet_' . $tenant->id;

        // 4️⃣ Verify tenant DB physically exists
        $databases = collect(DB::select("SHOW DATABASES LIKE '{$actualTenantDb}'"))
            ->pluck('Database (' . $actualTenantDb . ')')
            ->toArray();

        $this->assertTrue(in_array($actualTenantDb, $databases, true),
            "Tenant database {$actualTenantDb} was not created."
        );

        // 5️⃣ Run context check inside the tenant
        Tenancy::initialize($tenant);

        $this->assertTrue(Schema::hasTable('users'), 'Users table missing in tenant DB');
        $this->assertDatabaseHas('users', [
            'email' => "admin@{$this->tenantSlug}.test",
        ], 'tenant');

        Tenancy::end();
    }

    /** @test */
    public function it_rejects_reserved_slugs()
    {
        $reserved = ['admin', 'api', 'system'];

        foreach ($reserved as $slug) {
            $payload = [
                'company_name' => 'Forbidden Inc.',
                'slug' => $slug,
                'admin_name' => 'Tester',
                'admin_email' => "{$slug}@test.com",
                'admin_password' => 'secret123',
                'admin_password_confirmation' => 'secret123',
            ];

            $response = $this->postJson('/api/tenants/register', $payload);
            $response->assertStatus(422);
        }
    }

    /** @test */
    public function check_slug_endpoint_returns_availability()
    {
        // Create an existing tenant (DB will not be created in tests, only central record)
        $tenant = Tenant::create([
            'id' => 'slugcheck',
            'slug' => 'slugcheck',
            'name' => 'Slug Check Tenant',
        ]);

        // Available slug
        $this->getJson('/api/tenants/check-slug?slug=unique-slug')
            ->assertOk()
            ->assertJson(['available' => true]);

        // Unavailable slug (endpoint returns 200 OK with available: false)
        $this->getJson('/api/tenants/check-slug?slug=slugcheck')
            ->assertOk()
            ->assertJson(['available' => false]);
    }

    protected function tearDown(): void
    {
        // Drop test tenant databases to keep CI clean
        DB::statement("DROP DATABASE IF EXISTS {$this->tenantDb}");
        DB::statement("DROP DATABASE IF EXISTS timesheet_slugcheck");
        
        parent::tearDown();
    }
}

/* 

⸻

💡 Como usar

1️⃣ Correr testes

docker exec -it timesheet_app bash -lc "php artisan test --filter=TenantOnboardingTest"

2️⃣ Saída esperada

OK (3 tests, 12 assertions)

3️⃣ Em caso de erro

Verifica se:
	•	O utilizador timesheet tem privilégios CREATE DATABASE;
	•	O prefixo timesheet_ está definido em .env:

TENANCY_DATABASE_PREFIX=timesheet_


	•	O endpoint /api/tenants/register retorna HTTP 201.
*/
