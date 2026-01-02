<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Technician;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

final class ExpenseReportsTest extends TenantTestCase
{
    private function makeTenantAndUser(bool $withPermission): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'password' => 'password',
        ]);

        if ($withPermission) {
            $user->assignRole('Technician');
        } else {
            $user->syncRoles([]);
        }

        $user->refresh();

        $tech = Technician::create([
            'name' => 'Tech 1',
            'email' => $user->email,
            'role' => 'technician',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $project = Project::create([
            'name' => 'Project A',
            'description' => 'A',
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Expense::create([
            'technician_id' => $tech->id,
            'project_id' => $project->id,
            'date' => '2025-12-10',
            'amount' => 123.45,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'Lunch',
        ]);

        return [$user, $project];
    }

    public function test_export_csv_streams_a_download(): void
    {
        [$user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/expenses/export', [
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'format' => 'csv',
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $this->assertStringContainsString('.csv', (string) $res->headers->get('content-disposition'));
        $this->assertSame('text/csv; charset=UTF-8', (string) $res->headers->get('content-type'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('expense_id', $csv);
        $this->assertStringContainsString('user1@example.com', $csv);
    }

    public function test_export_xlsx_streams_a_download(): void
    {
        [$user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/expenses/export', [
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'format' => 'xlsx',
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $this->assertStringContainsString('.xlsx', (string) $res->headers->get('content-disposition'));
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $res->headers->get('content-type')
        );

        $bin = $res->streamedContent();
        $this->assertSame('PK', substr($bin, 0, 2));
    }

    public function test_regular_user_cannot_export_other_users_expenses(): void
    {
        [$user, $project] = $this->makeTenantAndUser(true);

        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'password' => 'password',
        ]);
        $user2->assignRole('Technician');
        $user2->refresh();

        $tech2 = Technician::create([
            'name' => 'Tech 2',
            'email' => $user2->email,
            'role' => 'technician',
            'user_id' => $user2->id,
            'is_active' => true,
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user2->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Expense::create([
            'technician_id' => $tech2->id,
            'project_id' => $project->id,
            'date' => '2025-12-10',
            'amount' => 50,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'Other user lunch',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/expenses/export', [
                // Attempt to export other user's data (should be ignored by scoping)
                'filters' => ['user_id' => $user2->id],
                'format' => 'csv',
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $csv = $res->streamedContent();

        $this->assertStringContainsString('user1@example.com', $csv);
        $this->assertStringNotContainsString('user2@example.com', $csv);
    }
}
