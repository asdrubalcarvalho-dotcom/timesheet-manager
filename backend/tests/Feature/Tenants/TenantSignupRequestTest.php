<?php

declare(strict_types=1);

namespace Tests\Feature\Tenants;

use App\Models\PendingTenantSignup;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TenantSignupRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function request_signup_deletes_pending_row_if_email_sending_fails(): void
    {
        // Force EmailRecipient::notify() to throw to simulate SMTP rejection/exception.
        Mockery::mock('overload:App\\Support\\EmailRecipient')
            ->shouldReceive('notify')
            ->once()
            ->andThrow(new \Exception('550 Rejected'));

        $payload = [
            'company_name' => 'Cleanup Test Co.',
            'slug' => 'cleanup-test',
            'admin_name' => 'Cleanup Admin',
            'admin_email' => 'cleanup@test.example',
            'admin_password' => 'secret123',
            'admin_password_confirmation' => 'secret123',
            'industry' => 'Testing',
            'country' => 'PT',
            'timezone' => 'Europe/Lisbon',
        ];

        $response = $this->postJson('/api/tenants/request-signup', $payload);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('pending_tenant_signups', [
            'slug' => 'cleanup-test',
            'admin_email' => 'cleanup@test.example',
        ]);
    }

    /** @test */
    public function check_slug_returns_unavailable_when_slug_exists_in_active_pending_signups(): void
    {
        PendingTenantSignup::create([
            'company_name' => 'Pending Co.',
            'slug' => 'pending-slug',
            'admin_name' => 'Pending Admin',
            'admin_email' => 'pending@test.example',
            'password_hash' => bcrypt('secret123'),
            'verification_token' => str_repeat('a', 64),
            'industry' => null,
            'country' => null,
            'timezone' => 'UTC',
            'expires_at' => Carbon::now()->addHours(24),
            'verified' => false,
        ]);

        $this->getJson('/api/tenants/check-slug?slug=pending-slug')
            ->assertOk()
            ->assertJson(['available' => false]);
    }
}
