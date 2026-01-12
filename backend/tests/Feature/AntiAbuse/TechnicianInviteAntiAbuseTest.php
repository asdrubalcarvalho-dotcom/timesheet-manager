<?php

namespace Tests\Feature\AntiAbuse;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TenantTestCase;

class TechnicianInviteAntiAbuseTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure required roles exist for controller behavior (assignRole('Technician')).
        Role::findOrCreate('Technician', 'web');

        // This suite tests anti-abuse behavior inside the controller.
        // We keep tenant + auth middleware, but bypass permission gating.
        $this->withoutMiddleware([
            PermissionMiddleware::class,
        ]);
    }

    public function test_disposable_email_invite_is_rejected_with_generic_message_and_safe_log(): void
    {
        Log::spy();

        $requestId = 'req-antiabuse-invite-1';
        $ip = '203.0.113.20';
        $userAgent = 'phpunit-invite-agent';

        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
        ]);

        Sanctum::actingAs($admin);

        $existingUsers = User::count();

        $response = $this
            ->withHeaders(['X-Request-Id' => $requestId])
            ->withServerVariables([
                'REMOTE_ADDR' => $ip,
                'HTTP_USER_AGENT' => $userAgent,
            ])
            ->postJson('/api/technicians', [
                'name' => 'Bot User',
                'email' => 'bot@mailinator.com',
                'role' => 'technician',
                'password' => 'password123',
                'send_invite' => true,
            ], $this->tenantHeaders());

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Please use a valid business or personal email address.',
        ]);

        $this->assertSame($existingUsers, User::count());

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

            if (($context['request_id'] ?? null) !== $requestId) {
                return false;
            }

            return !isset($context['email'])
                && !isset($context['admin_email'])
                && !isset($context['full_email']);
        })->once();
    }

    public function test_normal_invite_email_is_accepted_and_behavior_is_unchanged(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/technicians', [
            'name' => 'Normal User',
            'email' => 'normal.user@example.com',
            'role' => 'technician',
            'password' => 'password123',
            'send_invite' => false,
        ], $this->tenantHeaders());

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
        ]);
    }
}
