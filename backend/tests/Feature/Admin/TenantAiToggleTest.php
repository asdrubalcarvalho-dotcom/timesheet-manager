<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAiToggleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Toggle Tenant',
            'slug' => 'toggle-tenant',
        ]);

        // Reuse the central database during tests to avoid creating per-tenant schemas.
        $this->tenant->setInternal('db_name', config('database.connections.mysql.database'));
        $this->tenant->save();

        Role::firstOrCreate([
            'name' => 'Admin',
            'guard_name' => 'web',
        ]);

        $this->admin = User::factory()->create([
            'email' => 'admin@toggle.test',
        ]);

        $this->admin->assignRole('Admin');
    }

    /** @test */
    public function admin_can_enable_ai_flag(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/admin/tenants/{$this->tenant->slug}/ai", [
            'ai_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('tenant.ai_enabled', true);

        $this->assertTrue($this->tenant->fresh()->ai_enabled);
    }

    /** @test */
    public function admin_can_disable_ai_flag(): void
    {
        $this->tenant->update(['ai_enabled' => true]);

        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/admin/tenants/{$this->tenant->slug}/ai", [
            'ai_enabled' => false,
        ]);

        $response->assertOk()
            ->assertJsonPath('tenant.ai_enabled', false);

        $this->assertFalse($this->tenant->fresh()->ai_enabled);
    }

    /** @test */
    public function non_admin_users_cannot_toggle_ai(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson("/api/admin/tenants/{$this->tenant->slug}/ai", [
            'ai_enabled' => true,
        ]);

        $response->assertForbidden();
    }

    /** @test */
    public function ai_enabled_field_is_required(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/admin/tenants/{$this->tenant->slug}/ai", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['ai_enabled']);
    }

    /** @test */
    public function tenant_user_can_toggle_ai_via_billing_route(): void
    {
        tenancy()->initialize($this->tenant);

        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->putJson('/api/billing/ai-toggle', ['ai_enabled' => true]);

        $response->assertOk()
            ->assertJsonPath('tenant.ai_enabled', true);

        $this->assertTrue($this->tenant->fresh()->ai_enabled);

        tenancy()->end();
    }

    /** @test */
    public function unauthenticated_requests_cannot_toggle_ai_via_billing_route(): void
    {
        tenancy()->initialize($this->tenant);

        $response = $this->withHeaders(['X-Tenant' => $this->tenant->slug])
            ->putJson('/api/billing/ai-toggle', ['ai_enabled' => true]);

        $response->assertUnauthorized();

        tenancy()->end();
    }
}
