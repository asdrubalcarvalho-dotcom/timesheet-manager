<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Support\DateFormatter;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class TenantLocaleFormattingTest extends TenantTestCase
{
    public function test_eu_tenant_formats_dates_as_dd_mm_yyyy_and_exposes_locale_context(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'region' => 'EU',
                'locale' => 'pt_PT',
                'timezone' => 'UTC',
                'currency' => 'EUR',
                'week_start' => 'monday',
            ],
        ])->saveQuietly();

        $context = TenantContext::fromTenant($this->tenant);
        $formatter = new DateFormatter($context);

        $date = Carbon::create(2026, 1, 31, 12, 0, 0, 'UTC');
        $this->assertSame('31/01/2026', $formatter->date($date));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user', $this->tenantHeaders());
        $response->assertOk();
        $response->assertJsonPath('tenant_context.locale', 'pt-PT');
    }

    public function test_us_tenant_formats_dates_as_mm_dd_yyyy_and_exposes_locale_context(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'region' => 'US',
                'locale' => 'en_US',
                'timezone' => 'UTC',
                'currency' => 'USD',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $context = TenantContext::fromTenant($this->tenant);
        $formatter = new DateFormatter($context);

        $date = Carbon::create(2026, 1, 31, 12, 0, 0, 'UTC');
        $this->assertSame('01/31/2026', $formatter->date($date));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user', $this->tenantHeaders());
        $response->assertOk();
        $response->assertJsonPath('tenant_context.locale', 'en-US');
    }
}
