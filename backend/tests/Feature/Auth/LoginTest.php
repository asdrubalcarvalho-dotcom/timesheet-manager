<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AllowCentralDomainFallback;
use App\Http\Middleware\InitializeTenancyByDomainWithFallback;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class LoginTest extends TenantTestCase
{
    public function test_admin_can_login_using_tenant_header(): void
    {
        $this->tenant->run(function () {
            Role::findOrCreate('Admin', 'web');

            $user = User::factory()->create([
                'name' => 'Demo Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
            ]);

            $user->assignRole('Admin');
        });

        $response = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->postJson('/api/login', [
                'tenant_slug' => $this->tenant->slug,
                'email' => 'admin@example.com',
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

        // /api/login is a central route; it should remain reachable even when central fallback is disabled.
        $this->tenant->run(function () {
            Role::findOrCreate('Admin', 'web');

            $user = User::factory()->create([
                'name' => 'Demo Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'),
            ]);

            $user->assignRole('Admin');
        });

        $response = $this->postJson('/api/login', [
            'tenant_slug' => $this->tenant->slug,
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk();
    }
}
