<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowCentralDomainFallback;
use App\Http\Middleware\InitializeTenancyByDomainWithFallback;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_using_tenant_header(): void
    {
        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo',
            'owner_email' => 'admin@example.com',
            'status' => 'active',
            'plan' => 'standard',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
        ]);

        $response = $this->withHeaders(['X-Tenant' => $tenant->slug])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id',
                    'email',
                    'tenant' => ['id', 'slug'],
                ],
            ]);
    }

    public function test_central_domain_is_blocked_when_fallback_disabled(): void
    {
        config([
            'tenancy.domains.central_fallback.enabled' => false,
            'tenancy.domains.central_fallback.environments' => [],
        ]);

        $tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo',
            'owner_email' => 'admin@example.com',
            'status' => 'active',
            'plan' => 'standard',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Demo Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
        ]);

        $this->withMiddleware([
            AllowCentralDomainFallback::class,
            InitializeTenancyByDomainWithFallback::class,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ], [
            'X-Tenant' => $tenant->slug,
        ]);

        $response->assertForbidden();
    }
}
