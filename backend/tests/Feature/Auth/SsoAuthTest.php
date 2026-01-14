<?php

namespace Tests\Feature\Auth;

use App\Models\SocialAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Tests\TenantTestCase;

class SsoAuthTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable throttling for tests (but keep web/session + tenant middleware enabled).
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_microsoft_redirect_returns_302_and_does_not_throw_driver_not_supported(): void
    {
        config([
            'services.microsoft' => [
                'client_id' => 'test-client-id',
                'client_secret' => 'test-client-secret',
                'redirect' => 'http://api.localhost/auth/microsoft/callback',
                'tenant' => 'common',
            ],
        ]);

        $response = $this->get('/auth/microsoft/redirect?tenant=' . urlencode($this->tenant->slug));

        $response->assertStatus(302);
        $this->assertStringContainsString('login.microsoftonline.com', (string) $response->headers->get('Location'));
    }

    public function test_sso_redirect_is_blocked_when_tenant_signup_not_completed(): void
    {
        // TenantTestCase initializes tenancy by default; end it so the request goes through
        // the normal tenant resolution middleware path.
        tenancy()->end();

        $response = $this->getJson('/auth/microsoft/redirect?tenant=pending-signup-slug');

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Tenant signup not completed',
        ]);
    }

    public function test_existing_tenant_user_can_log_in_via_google_sso(): void
    {
        User::factory()->create([
            'email' => 'user@sso.test',
            'name' => 'SSO User',
        ]);

        $state = app(\App\Services\Auth\SsoStateService::class)->generate($this->tenant->slug);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'google-test-123'; }
            public function getEmail() { return 'user@sso.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?state={$state}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertOk();
        $response->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => "tenant-{$this->tenant->id}",
        ], 'tenant');
    }

    public function test_disposable_email_is_rejected_during_callback_before_user_lookup(): void
    {
        Log::spy();

        $existingUsers = User::count();

        $state = app(\App\Services\Auth\SsoStateService::class)->generate($this->tenant->slug);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'disposable-bot-123'; }
            public function getEmail() { return 'bot@mailinator.com'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?state={$state}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Please use a valid business or personal email address.',
        ]);

        $afterUsers = User::count();
        $this->assertSame($existingUsers, $afterUsers);

        Log::shouldHaveReceived('warning')->withArgs(function ($message, $context = []) {
            return $message === 'email_policy.rejected'
                && ($context['email_domain'] ?? null) === 'mailinator.com'
                && ($context['reason'] ?? null) === 'disposable_email_domain'
                && !empty($context['endpoint'] ?? null)
                && (($context['provider'] ?? null) === 'google')
                && (($context['tenant_slug'] ?? null) === $this->tenant->slug)
                && empty($context['email']);
        })->once();
    }

    public function test_non_existing_user_is_rejected_and_no_user_is_created(): void
    {
        $existingUsers = User::count();

        $state = app(\App\Services\Auth\SsoStateService::class)->generate($this->tenant->slug);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'missing-user-456'; }
            public function getEmail() { return 'missing@sso.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?state={$state}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'No account exists for this email in this workspace.',
        ]);

        $afterUsers = User::count();
        $this->assertSame($existingUsers, $afterUsers);
    }

    public function test_invalid_state_is_rejected(): void
    {
        $response = $this->getJson(
            '/auth/google/callback?state=bad&code=fake',
            $this->tenantHeaders()
        );

        $response->assertStatus(400);
        $response->assertJson([
            'message' => 'Unable to sign in with SSO.',
        ]);
    }

    // =========================================================================
    // SSO-2 LINKING TESTS
    // =========================================================================

    public function test_authenticated_user_can_start_link_flow(): void
    {
        $user = User::factory()->create([
            'email' => 'user@link.test',
            'name' => 'Link User',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/auth/sso/google/link/start',
            [],
            $this->tenantHeaders()
        );

        $response->assertOk();
        $response->assertJsonStructure(['link_state', 'redirect_url']);
    }

    public function test_link_flow_creates_social_account_and_is_idempotent(): void
    {
        $user = User::factory()->create([
            'email' => 'user@link.test',
            'name' => 'Link User',
        ]);

        Sanctum::actingAs($user);

        $linkState = app(\App\Services\Auth\SsoStateService::class)
            ->generateLinkState($this->tenant->slug, $user->id, 'google');

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'google-user-123'; }
            public function getEmail() { return 'user@link.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        // First link
        $response = $this->getJson(
            "/auth/google/callback?mode=link&state={$linkState}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertOk();
        $response->assertJson(['message' => 'Account linked successfully.']);

        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-123',
        ], 'tenant');

        // Second link (idempotent)
        $linkState2 = app(\App\Services\Auth\SsoStateService::class)
            ->generateLinkState($this->tenant->slug, $user->id, 'google');

        $response2 = $this->getJson(
            "/auth/google/callback?mode=link&state={$linkState2}&code=fake",
            $this->tenantHeaders()
        );

        $response2->assertOk();
        $response2->assertJson(['message' => 'Account already linked.']);

        // Verify only one social_account row exists
        $this->assertSame(1, SocialAccount::where('user_id', $user->id)->count());
    }

    public function test_linked_provider_user_id_can_login_even_if_email_differs(): void
    {
        $user = User::factory()->create([
            'email' => 'original@link.test',
            'name' => 'Link User',
        ]);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-456',
            'provider_email' => 'different@link.test',
        ]);

        $state = app(\App\Services\Auth\SsoStateService::class)->generate($this->tenant->slug);

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'google-user-456'; }
            public function getEmail() { return 'different@link.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?state={$state}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertOk();
        $response->assertJsonPath('user.id', $user->id);
        $response->assertJsonPath('user.email', 'original@link.test');
    }

    public function test_linking_same_provider_user_id_to_different_user_returns_409(): void
    {
        $user1 = User::factory()->create(['email' => 'user1@link.test']);
        $user2 = User::factory()->create(['email' => 'user2@link.test']);

        // Link to user1 first
        SocialAccount::create([
            'user_id' => $user1->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-789',
            'provider_email' => 'user1@link.test',
        ]);

        // Try to link same provider_user_id to user2
        Sanctum::actingAs($user2);

        $linkState = app(\App\Services\Auth\SsoStateService::class)
            ->generateLinkState($this->tenant->slug, $user2->id, 'google');

        $driver = \Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('user')->andReturn(new class {
            public function getId() { return 'google-user-789'; }
            public function getEmail() { return 'user2@link.test'; }
            public function getRaw() { return ['email_verified' => true]; }
        });

        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $response = $this->getJson(
            "/auth/google/callback?mode=link&state={$linkState}&code=fake",
            $this->tenantHeaders()
        );

        $response->assertStatus(409);
        $response->assertJson(['message' => 'This SSO account is already linked to another user.']);

        // Verify user2 was NOT linked
        $this->assertSame(0, SocialAccount::where('user_id', $user2->id)->count());
    }

    public function test_user_already_linked_cannot_start_link_flow_again(): void
    {
        $user = User::factory()->create(['email' => 'user@linked.test']);

        SocialAccount::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-999',
            'provider_email' => 'user@linked.test',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(
            '/api/auth/sso/google/link/start',
            [],
            $this->tenantHeaders()
        );

        $response->assertStatus(409);
        $response->assertJson(['message' => 'This provider is already linked to your account.']);
    }
}
