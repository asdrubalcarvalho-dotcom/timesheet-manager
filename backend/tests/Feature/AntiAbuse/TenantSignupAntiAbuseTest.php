<?php

namespace Tests\Feature\AntiAbuse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantSignupAntiAbuseTest extends TestCase
{
    public function test_disposable_email_is_rejected_with_generic_message_and_safe_log(): void
    {
        Mail::fake();
        Log::spy();

        $requestId = 'req-antiabuse-signup-1';
        $ip = '203.0.113.10';
        $userAgent = 'phpunit-test-agent';

        $unique = (string) Str::uuid();

        $response = $this
            ->withHeaders(['X-Request-Id' => $requestId])
            ->withServerVariables([
                'REMOTE_ADDR' => $ip,
                'HTTP_USER_AGENT' => $userAgent,
            ])
            ->postJson('/api/tenants/request-signup', [
                'company_name' => 'Acme Inc',
                'slug' => 'acme-antiabuse-' . substr($unique, 0, 8),
                'admin_name' => 'Acme Owner',
                'admin_email' => 'bot+' . substr($unique, 0, 8) . '@mailinator.com',
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'password123',
                'timezone' => 'UTC',
            ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Please use a valid business or personal email address.',
        ]);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) use ($requestId, $ip, $userAgent) {
            if ($message !== 'email_policy.rejected') {
                return false;
            }

            if (($context['email_domain'] ?? null) !== 'mailinator.com') {
                return false;
            }

            if (($context['ip'] ?? null) !== $ip) {
                return false;
            }

            if (($context['user_agent'] ?? null) !== $userAgent) {
                return false;
            }

            if (!isset($context['reason']) || !isset($context['endpoint'])) {
                return false;
            }

            if (($context['request_id'] ?? null) !== $requestId) {
                return false;
            }

            return !isset($context['email'])
                && !isset($context['admin_email'])
                && !isset($context['full_email']);
        })->once();
    }

    public function test_normal_email_signup_is_accepted_and_behavior_is_unchanged(): void
    {
        Mail::fake();

        $unique = (string) Str::uuid();

        $response = $this->postJson('/api/tenants/request-signup', [
            'company_name' => 'Acme Inc',
            'slug' => 'acme-antiabuse-ok-' . substr($unique, 0, 8),
            'admin_name' => 'Acme Owner',
            'admin_email' => 'owner+' . substr($unique, 0, 8) . '@example.com',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
            'timezone' => 'UTC',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'pending',
        ]);
    }

    public function test_signup_is_rate_limited_by_public_auth_limiter(): void
    {
        Mail::fake();

        $unique = substr((string) Str::uuid(), 0, 8);

        $ip = '203.0.113.11';
        $userAgent = 'phpunit-throttle-agent';

        for ($i = 0; $i < 5; $i++) {
            $response = $this
                ->withServerVariables([
                    'REMOTE_ADDR' => $ip,
                    'HTTP_USER_AGENT' => $userAgent,
                ])
                ->postJson('/api/tenants/request-signup', [
                    'company_name' => 'Acme Inc',
                    'slug' => 'acme-antiabuse-throttle-' . $unique . '-' . $i,
                    'admin_name' => 'Acme Owner',
                    'admin_email' => 'owner' . $i . '+' . $unique . '@example.com',
                    'admin_password' => 'password123',
                    'admin_password_confirmation' => 'password123',
                    'timezone' => 'UTC',
                ]);

            $response->assertOk();
        }

        $sixth = $this
            ->withServerVariables([
                'REMOTE_ADDR' => $ip,
                'HTTP_USER_AGENT' => $userAgent,
            ])
            ->postJson('/api/tenants/request-signup', [
                'company_name' => 'Acme Inc',
                'slug' => 'acme-antiabuse-throttle-' . $unique . '-5',
                'admin_name' => 'Acme Owner',
                'admin_email' => 'owner5+' . $unique . '@example.com',
                'admin_password' => 'password123',
                'admin_password_confirmation' => 'password123',
                'timezone' => 'UTC',
            ]);

        $sixth->assertStatus(429);
    }
}
