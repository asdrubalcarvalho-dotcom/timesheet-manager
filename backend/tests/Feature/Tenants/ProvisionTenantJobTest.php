<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Jobs\ProvisionTenantJob;
use App\Models\PendingTenantSignup;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProvisionTenantJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_tenant_active_and_is_idempotent(): void
    {
        config([
            'cache.default' => 'array',
            // Avoid any accidental external gateway calls in this suite.
            'payments.driver' => 'none',
        ]);

        $tenant = Tenant::create([
            'name' => 'Acme Inc',
            'slug' => 'acme-job-' . substr(uniqid('', true), -8),
            'owner_email' => 'owner@example.com',
            'status' => 'provisioning',
            'plan' => 'trial',
            'timezone' => 'UTC',
            'trial_ends_at' => now()->addDays(14),
        ]);

        PendingTenantSignup::create([
            'company_name' => $tenant->name,
            'slug' => $tenant->slug,
            'admin_name' => 'Acme Owner',
            'admin_email' => $tenant->owner_email,
            'password_hash' => Hash::make('password123'),
            'verification_token' => 'tok_' . substr(uniqid('', true), -8),
            'timezone' => 'UTC',
            'expires_at' => now()->addHour(),
            'verified' => true,
            'email_verified_at' => now(),
            'completed_at' => now(),
        ]);

        $this->mock(TenantProvisioningService::class, function ($mock) use ($tenant): void {
            $mock->shouldReceive('provisionFromPendingSignup')
                ->once()
                ->withArgs(function (Tenant $t, PendingTenantSignup $pending) use ($tenant): bool {
                    return $t->id === $tenant->id && $pending->slug === $tenant->slug;
                })
                ->andReturn($tenant);
        });

        $job = new ProvisionTenantJob($tenant->id);
        $job->handle(app(TenantProvisioningService::class));

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertSame('active', $tenant->settings['provisioning_status'] ?? null);
        $this->assertNull($tenant->settings['provisioning_error'] ?? null);

        // Second run should exit early and not re-provision.
        $job->handle(app(TenantProvisioningService::class));

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
    }
}
