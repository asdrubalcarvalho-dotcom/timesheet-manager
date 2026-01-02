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

final class ExpenseSummaryReportTest extends TenantTestCase
{
    public function test_summary_for_regular_user_is_scoped_to_their_expenses(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'password' => 'password',
        ]);
        $user1->assignRole('Technician');
        $user1->refresh();

        $tech1 = Technician::create([
            'name' => 'Tech 1',
            'email' => $user1->email,
            'role' => 'technician',
            'user_id' => $user1->id,
            'is_active' => true,
        ]);

        $project = Project::create([
            'name' => 'Project A',
            'description' => 'A',
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user1->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Expense::create([
            'technician_id' => $tech1->id,
            'project_id' => $project->id,
            'date' => '2025-12-01',
            'amount' => 10,
            'category' => 'Meals',
            'status' => 'submitted',
            'description' => 'D1',
        ]);

        Expense::create([
            'technician_id' => $tech1->id,
            'project_id' => $project->id,
            'date' => '2025-12-02',
            'amount' => 20,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'D2',
        ]);

        Expense::create([
            'technician_id' => $tech1->id,
            'project_id' => $project->id,
            'date' => '2025-12-03',
            'amount' => 30,
            'category' => 'Travel',
            'status' => 'rejected',
            'description' => 'D3',
        ]);

        // Other user's expense should not appear
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
            'date' => '2025-12-01',
            'amount' => 999,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'Other',
        ]);

        Sanctum::actingAs($user1);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/expenses/summary', [
                'from' => '2025-12-01',
                'to' => '2025-12-03',
                'group_by' => ['user'],
                'period' => 'day',
            ]);

        $res->assertOk();

        $rows = $res->json('rows');
        $this->assertIsArray($rows);
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertSame($user1->id, $row['user_id']);
        }

        $byPeriod = collect($rows)->keyBy('period');

        $this->assertEquals(10.0, $byPeriod['2025-12-01']['total_amount']);
        $this->assertSame(1, $byPeriod['2025-12-01']['total_entries']);

        $this->assertEquals(20.0, $byPeriod['2025-12-02']['total_amount']);
        $this->assertSame(1, $byPeriod['2025-12-02']['total_entries']);

        $this->assertEquals(30.0, $byPeriod['2025-12-03']['total_amount']);
        $this->assertSame(1, $byPeriod['2025-12-03']['total_entries']);
    }

    public function test_summary_for_elevated_user_sees_all_users(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::create([
            'name' => 'Manager',
            'email' => 'manager@example.com',
            'password' => 'password',
        ]);
        $manager->assignRole('Manager');
        $manager->refresh();

        $project = Project::create([
            'name' => 'Project A',
            'description' => 'A',
            'status' => 'active',
        ]);

        $user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'password' => 'password',
        ]);
        $user1->assignRole('Technician');
        $user1->refresh();

        $tech1 = Technician::create([
            'name' => 'Tech 1',
            'email' => $user1->email,
            'role' => 'technician',
            'user_id' => $user1->id,
            'is_active' => true,
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user1->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

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
            'technician_id' => $tech1->id,
            'project_id' => $project->id,
            'date' => '2025-12-01',
            'amount' => 10,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'U1',
        ]);

        Expense::create([
            'technician_id' => $tech2->id,
            'project_id' => $project->id,
            'date' => '2025-12-01',
            'amount' => 20,
            'category' => 'Meals',
            'status' => 'approved',
            'description' => 'U2',
        ]);

        Sanctum::actingAs($manager);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/expenses/summary', [
                'from' => '2025-12-01',
                'to' => '2025-12-01',
                'group_by' => ['user'],
                'period' => 'day',
            ]);

        $res->assertOk();

        $rows = collect($res->json('rows'));
        $this->assertSameCanonicalIds([$user1->id, $user2->id], $rows->pluck('user_id')->all());
    }

    /**
     * @param array<int,int|null> $expected
     * @param array<int,int|null> $actual
     */
    private function assertSameCanonicalIds(array $expected, array $actual): void
    {
        $expected = array_values(array_unique(array_map(fn ($v) => $v === null ? null : (int) $v, $expected)));
        $actual = array_values(array_unique(array_map(fn ($v) => $v === null ? null : (int) $v, $actual)));
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}
