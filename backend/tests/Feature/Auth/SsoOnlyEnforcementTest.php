<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Tests\TenantTestCase;

class SsoOnlyEnforcementTest extends TenantTestCase
{
    public function test_password_login_is_rejected_when_require_sso_true(): void
    {
        Log::spy();

        // Ensure the password-login request starts in central context.
        tenancy()->end();
        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        $this->tenant->forceFill(['require_sso' => true])->saveQuietly();

        $tenantQueries = 0;
        DB::listen(function ($query) use (&$tenantQueries) {
            if (($query->connectionName ?? null) === 'tenant') {
                $tenantQueries++;
            }
        });

        $response = $this->postJson('/api/login', [
            'tenant_slug' => $this->tenant->slug,
            'email' => 'user@sso-only.test',
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'This workspace requires Single Sign-On authentication.',
        ]);

        $this->assertSame(0, $tenantQueries, 'SSO-only password block must not query the tenant database.');

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return $message === 'auth.password_blocked_sso_only'
                && ($context['event'] ?? null) === 'auth.password_blocked_sso_only'
                && ($context['tenant'] ?? null) === $this->tenant->slug
                && is_string($context['ip'] ?? null)
                && ($context['ip'] ?? '') !== '';
        })->once();
    }

    public function test_password_login_is_allowed_when_require_sso_false(): void
    {
        tenancy()->end();
        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        $this->tenant->forceFill(['require_sso' => false])->saveQuietly();

        // Create the user in the tenant database (the login request itself must start in central).
        DB::setDefaultConnection('tenant');
        config(['database.default' => 'tenant']);
        User::factory()->create([
            'email' => 'user@password-ok.test',
            'password' => bcrypt('password123'),
        ]);

        DB::setDefaultConnection('mysql');
        config(['database.default' => 'mysql']);

        $response = $this->postJson('/api/login', [
            'tenant_slug' => $this->tenant->slug,
            'email' => 'user@password-ok.test',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);
    }

    public function test_sso_login_is_allowed_when_require_sso_true_and_token_abilities_unchanged(): void
    {
        $this->tenant->forceFill(['require_sso' => true])->saveQuietly();

        $this->tenant->run(function () {
            User::factory()->create([
                'email' => 'user@sso-only-sso.test',
                'name' => 'SSO User',
            ]);
        });

        $state = app(\App\Services\Auth\SsoStateService::class)->generate($this->tenant->slug);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'google-sso-only-123'; }
            public function getEmail() { return 'user@sso-only-sso.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?state={$state}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $tokenRow = \DB::connection('tenant')
            ->table('personal_access_tokens')
            ->where('name', "tenant-{$this->tenant->id}")
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($tokenRow);

        $abilities = json_decode((string) $tokenRow->abilities, true);
        $this->assertIsArray($abilities);
        $this->assertContains("tenant:{$this->tenant->id}", $abilities);
    }
}
