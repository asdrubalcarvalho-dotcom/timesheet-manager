<?php

namespace Tests\Feature\AntiAbuse;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TenantTestCase;

class LoginCaptchaTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // This suite targets adaptive CAPTCHA behavior; throttle makes it flaky.
        $this->withoutMiddleware(ThrottleRequests::class);

        config([
            'captcha.enabled' => true,
            'captcha.mode' => 'adaptive',
            'captcha.provider' => 'turnstile',
            'captcha.secret' => 'test-secret',
            'captcha.site_key' => 'test-site-key',
            'captcha.login.failure_threshold' => 3,
        ]);

        // Create user in tenant DB.
        tenancy()->end();
        DB::setDefaultConnection('tenant');
        config(['database.default' => 'tenant']);

        User::factory()->create([
            'email' => 'user@login-captcha.test',
            'password' => Hash::make('correct-password'),
        ]);

        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);
    }

    public function test_after_three_failed_logins_next_attempt_requires_captcha(): void
    {
        // 3 failed attempts
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/login', [
                'tenant_slug' => $this->tenant->slug,
                'email' => 'user@login-captcha.test',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(422);
        }

        // Next attempt should require captcha (even if creds are correct)
        $fourth = $this->postJson('/api/login', [
            'tenant_slug' => $this->tenant->slug,
            'email' => 'user@login-captcha.test',
            'password' => 'correct-password',
        ]);

        $fourth->assertStatus(422);
        $fourth->assertJson([
            'message' => 'Please complete the security check.',
            'code' => 'captcha_required',
            'captcha' => [
                'provider' => 'turnstile',
                'site_key' => 'test-site-key',
            ],
        ]);

        Http::fake([
            '*turnstile*' => Http::response(['success' => true], 200),
        ]);

        // Now with captcha + correct creds should succeed
        $withCaptcha = $this->postJson('/api/login', [
            'tenant_slug' => $this->tenant->slug,
            'email' => 'user@login-captcha.test',
            'password' => 'correct-password',
            'captcha_token' => 'test-token',
        ]);

        $withCaptcha->assertOk();
        $withCaptcha->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }
}
