<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Tenancy\TenantWeekConfig;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class SetTenantContextTest extends TenantTestCase
{
    public function test_us_tenant_applies_context_and_sets_headers(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                // Intentionally omit timezone to exercise US default fallback.
                'currency' => 'USD',
            ],
        ])->saveQuietly();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user', $this->tenantHeaders());

        $response->assertOk();
        $response->assertHeader('X-Tenant-Locale', 'en_US');
        $response->assertHeader('X-Tenant-Timezone', 'America/New_York');
        $response->assertHeader('X-Tenant-Week-Start', TenantWeekConfig::SUNDAY);
        $response->assertHeader('X-Tenant-Currency', 'USD');
    }

    public function test_eu_tenant_applies_context_and_sets_headers(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'pt_PT',
                'timezone' => 'Europe/Lisbon',
                'currency' => 'EUR',
                'week_start' => 'monday',
            ],
        ])->saveQuietly();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user', $this->tenantHeaders());

        $response->assertOk();
        $response->assertHeader('X-Tenant-Locale', 'pt_PT');
        $response->assertHeader('X-Tenant-Timezone', 'Europe/Lisbon');
        $response->assertHeader('X-Tenant-Week-Start', TenantWeekConfig::MONDAY);
        $response->assertHeader('X-Tenant-Currency', 'EUR');
    }
}
