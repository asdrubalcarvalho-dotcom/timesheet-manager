<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Services\Tenancy\TenantDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_tenant_fully_removes_central_records_and_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'upg2ai',
            'owner_email' => 'owner@upg2ai.test',
        ]);

        $tenantId = (string) $tenant->id;
        $slug = (string) $tenant->slug;
        $now = now();

        DB::table('domains')->insert([
            'domain' => $slug . '.vendaslive.com',
            'tenant_id' => $tenantId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('companies')->insert([
            'tenant_id' => $tenantId,
            'name' => 'UPG2AI',
            'timezone' => 'UTC',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('subscriptions')->insert([
            'tenant_id' => $tenantId,
            'plan' => 'starter',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('billing_profiles')->insert([
            'tenant_id' => $tenantId,
            'gateway' => 'stripe',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('billing_invoices')->insert([
            'stripe_invoice_id' => 'in_test_' . $tenantId,
            'stripe_subscription_id' => null,
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'status' => 'draft',
            'amount_due' => 0,
            'amount_paid' => 0,
            'currency' => 'EUR',
            'erp_processed' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('billing_payment_failures')->insert([
            'tenant_id' => $tenantId,
            'tenant_slug' => $slug,
            'status' => 'pending',
            'amount' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('payments')->insert([
            'tenant_id' => $tenantId,
            'amount' => 10,
            'currency' => 'EUR',
            'status' => 'pending',
            'gateway' => 'fake_card',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('plan_change_history')->insert([
            'tenant_id' => $tenantId,
            'old_plan' => null,
            'new_plan' => 'starter',
            'change_type' => 'initial',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('subscription_plan_history')->insert([
            'tenant_id' => $tenantId,
            'previous_plan' => null,
            'new_plan' => 'starter',
            'changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('tenant_metrics_daily')->insert([
            'tenant_id' => $tenantId,
            'date' => $now->toDateString(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('pending_tenant_signups')->insert([
            'company_name' => 'UPG2AI',
            'slug' => $slug,
            'admin_name' => 'Owner',
            'admin_email' => 'owner@upg2ai.test',
            'password_hash' => Hash::make('password123'),
            'verification_token' => str_repeat('a', 64),
            'timezone' => 'UTC',
            'expires_at' => $now->copy()->addDay(),
            'verified' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('admin_actions')->insert([
            'actor_user_id' => null,
            'actor_email' => 'audit@upg2ai.test',
            'action' => 'tenant_test_entry',
            'tenant_id' => $tenantId,
            'payload' => json_encode(['slug' => $slug]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = app(TenantDeletionService::class);
        $service->deleteTenantFully($slug);

        $this->assertDatabaseMissing('tenants', ['id' => $tenantId]);
        $this->assertDatabaseMissing('domains', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('companies', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('subscriptions', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('billing_profiles', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('billing_invoices', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('billing_invoices', ['tenant_slug' => $slug]);
        $this->assertDatabaseMissing('billing_payment_failures', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('billing_payment_failures', ['tenant_slug' => $slug]);
        $this->assertDatabaseMissing('payments', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('plan_change_history', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('subscription_plan_history', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('tenant_metrics_daily', ['tenant_id' => $tenantId]);
        $this->assertDatabaseMissing('pending_tenant_signups', ['slug' => $slug]);
        $this->assertDatabaseMissing('admin_actions', ['tenant_id' => $tenantId]);

        $service->deleteTenantFully($slug);

        $this->assertDatabaseMissing('tenants', ['id' => $tenantId]);
        $this->assertDatabaseMissing('pending_tenant_signups', ['slug' => $slug]);
    }
}
