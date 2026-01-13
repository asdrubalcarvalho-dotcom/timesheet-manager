<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PendingTenantSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantSignupVerificationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid flakiness due to rate limiters in this suite.
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'app.url' => 'http://api.test',
            'app.frontend_url' => 'http://app.test',
        ]);
    }

    public function test_request_signup_returns_backend_verification_url_in_testing(): void
    {
        Mail::fake();

        $unique = substr((string) Str::uuid(), 0, 8);

        $response = $this->postJson('/api/tenants/request-signup', [
            'company_name' => 'Acme Inc',
            'slug' => 'acme-verify-' . $unique,
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . $unique . '@example.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'status',
            'verification_url',
            'verification_token',
        ]);

        $url = (string) $response->json('verification_url');
        $this->assertStringStartsWith('http://api.test/tenants/verify-signup?token=', $url);
    }

    public function test_verify_signup_redirect_marks_email_verified_and_is_idempotent(): void
    {
        $token = 'tok_' . substr((string) Str::uuid(), 0, 8);

        $pending = PendingTenantSignup::create([
            'company_name' => 'Acme Inc',
            'slug' => 'acme-redirect-' . substr((string) Str::uuid(), 0, 8),
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . substr((string) Str::uuid(), 0, 8) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'verification_token' => $token,
            'timezone' => 'UTC',
            'expires_at' => now()->addHour(),
            'verified' => false,
        ]);

        $first = $this->get('/tenants/verify-signup?token=' . urlencode($token));
        $first->assertRedirect();

        $location = (string) $first->headers->get('Location');
        $this->assertStringStartsWith('http://app.test/verify-signup?', $location);

        $parsed = parse_url($location);
        $query = [];
        parse_str($parsed['query'] ?? '', $query);

        $this->assertSame('1', $query['verified'] ?? null);
        $this->assertSame($token, $query['token'] ?? null);

        $pending->refresh();
        $this->assertTrue((bool) $pending->verified);
        $this->assertNotNull($pending->email_verified_at);

        // Second click should remain successful and not delete the pending signup.
        $second = $this->get('/tenants/verify-signup?token=' . urlencode($token));
        $second->assertRedirect();

        $this->assertDatabaseHas('pending_tenant_signups', [
            'id' => $pending->id,
            'verification_token' => $token,
        ]);
    }

    public function test_complete_signup_requires_email_verification(): void
    {
        $token = 'tok_' . substr((string) Str::uuid(), 0, 8);

        PendingTenantSignup::create([
            'company_name' => 'Acme Inc',
            'slug' => 'acme-complete-' . substr((string) Str::uuid(), 0, 8),
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . substr((string) Str::uuid(), 0, 8) . '@example.com',
            'password_hash' => Hash::make('password123'),
            'verification_token' => $token,
            'timezone' => 'UTC',
            'expires_at' => now()->addHour(),
            'verified' => false,
        ]);

        $response = $this->postJson('/api/tenants/complete-signup', [
            'token' => $token,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'code' => 'email_not_verified',
        ]);
    }
}
