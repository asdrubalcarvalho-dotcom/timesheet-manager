<?php

namespace Tests\Feature\AntiAbuse;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class TenantSignupCaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite targets CAPTCHA behavior; throttle makes tests flaky across runs.
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'captcha.enabled' => true,
            'captcha.mode' => 'adaptive',
            'captcha.provider' => 'turnstile',
            'captcha.secret' => 'test-secret',
            'captcha.site_key' => 'test-site-key',
            'captcha.risk_domains' => ['gmail.com'],
        ]);
    }

    public function test_risk_domain_triggers_captcha_required_on_request_signup(): void
    {
        Mail::fake();

        $unique = substr((string) Str::uuid(), 0, 8);

        $response = $this->postJson('/api/tenants/request-signup', [
            'company_name' => 'Acme Inc',
            'slug' => 'acme-captcha-' . $unique,
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . $unique . '@gmail.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'timezone' => 'UTC',
            'legal_accepted' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Please complete the security check.',
            'code' => 'captcha_required',
            'captcha' => [
                'provider' => 'turnstile',
                'site_key' => 'test-site-key',
            ],
        ]);
    }

    public function test_valid_captcha_token_allows_request_signup_to_proceed(): void
    {
        Mail::fake();

        Http::fake([
            '*turnstile*' => Http::response(['success' => true], 200),
        ]);

        $unique = substr((string) Str::uuid(), 0, 8);

        $response = $this->postJson('/api/tenants/request-signup', [
            'company_name' => 'Acme Inc',
            'slug' => 'acme-captcha-ok-' . $unique,
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . $unique . '@gmail.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'timezone' => 'UTC',
            'captcha_token' => 'test-token',
            'legal_accepted' => true,
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'pending',
        ]);
    }
}
