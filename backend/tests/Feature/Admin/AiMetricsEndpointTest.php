<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiMetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('ai_suggestion_feedback')) {
            Schema::create('ai_suggestion_feedback', function (Blueprint $table) {
                $table->id();
                $table->char('tenant_id', 26);
                $table->string('status', 20);
                $table->timestamps();
            });
        }
    }

    /** @test */
    public function admin_can_retrieve_ai_metrics(): void
    {
        $this->seedFeedback([
            ['tenant_id' => '01AAAAAAA11111111111111111', 'status' => 'accepted', 'daysAgo' => 5],
            ['tenant_id' => '01AAAAAAA11111111111111111', 'status' => 'rejected', 'daysAgo' => 4],
            ['tenant_id' => '01BBBBBBB22222222222222222', 'status' => 'accepted', 'daysAgo' => 3],
            ['tenant_id' => '01CCCCCCC33333333333333333', 'status' => 'ignored', 'daysAgo' => 2],
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/ai/metrics?days=30');

        $response->assertOk()->assertExactJson([
            'window_days' => 30,
            'tenants_with_ai' => 3,
            'suggestions_shown' => 4,
            'accepted_rate' => 0.5,
            'rejected_rate' => 0.25,
            'ignored_rate' => 0.25,
        ]);
    }

    /** @test */
    public function metrics_respect_requested_window(): void
    {
        $this->seedFeedback([
            ['tenant_id' => '01AAAAAAA11111111111111111', 'status' => 'accepted', 'daysAgo' => 40],
            ['tenant_id' => '01BBBBBBB22222222222222222', 'status' => 'accepted', 'daysAgo' => 10],
        ]);

        $this->actingAsAdmin();

        $response = $this->getJson('/api/admin/ai/metrics?days=30');

        $response->assertOk()->assertExactJson([
            'window_days' => 30,
            'tenants_with_ai' => 1,
            'suggestions_shown' => 1,
            'accepted_rate' => 1.0,
            'rejected_rate' => 0.0,
            'ignored_rate' => 0.0,
        ]);
    }

    /** @test */
    public function non_admin_users_cannot_access_metrics(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/ai/metrics')->assertForbidden();
    }

    private function actingAsAdmin(): void
    {
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Sanctum::actingAs($admin);
    }

    private function seedFeedback(array $rows): void
    {
        foreach ($rows as $row) {
            DB::table('ai_suggestion_feedback')->insert([
                'tenant_id' => $row['tenant_id'],
                'status' => $row['status'],
                'created_at' => now()->subDays($row['daysAgo'])->startOfMinute(),
                'updated_at' => now()->subDays($row['daysAgo'])->startOfMinute(),
            ]);
        }
    }
}
