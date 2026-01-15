<?php

namespace Tests\Feature\Travels;

use App\Models\User;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class EnsureTenantBootstrappedTest extends TenantTestCase
{
    public function test_new_tenant_can_call_travels_by_date_without_permission_does_not_exist(): void
    {
        // Enable travels module for the tenant so we reach controller/policy.
        Feature::for($this->tenant)->activate('travels');

        $user = User::factory()->create([
            'email' => 'user@travels.test',
        ]);

        Sanctum::actingAs($user);

        // We expect authorization to fail (403) for a user without roles/permissions,
        // but the request must not crash with PermissionDoesNotExist.
        $response = $this->getJson(
            '/api/travels/by-date?month=2026-01',
            $this->tenantHeaders()
        );

        $response->assertForbidden();
    }
}
