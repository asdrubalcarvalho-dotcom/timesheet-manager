<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class ProvisioningStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'app.domain' => 'example.test',
            'app.url' => 'http://api.test',
        ]);
    }

    public function test_provisioning_status_endpoint_transitions(): void
    {
        $slug = 'status-' . substr(uniqid('', true), -8);

        $tenant = Tenant::create([
            'name' => 'Acme Inc',
            'slug' => $slug,
            'owner_email' => 'owner@example.com',
            'status' => 'provisioning',
            'plan' => 'trial',
            'timezone' => 'UTC',
            'trial_ends_at' => now()->addDays(14),
            'settings' => [
                'provisioning_status' => 'provisioning',
            ],
        ]);

        $resp1 = $this->getJson('/api/tenants/provisioning-status?slug=' . urlencode($slug));
        $resp1->assertOk();
        $resp1->assertJson([
            'slug' => $slug,
            'status' => 'provisioning',
            'error' => null,
        ]);

        $tenant->forceFill([
            'status' => 'active',
            'settings' => [
                'provisioning_status' => 'active',
                'provisioning_error' => null,
            ],
        ])->save();

        $resp2 = $this->getJson('/api/tenants/provisioning-status?slug=' . urlencode($slug));
        $resp2->assertOk();
        $resp2->assertJson([
            'slug' => $slug,
            'status' => 'active',
            'error' => null,
        ]);
        $this->assertNotEmpty((string) $resp2->json('login_url'));
    }
}
