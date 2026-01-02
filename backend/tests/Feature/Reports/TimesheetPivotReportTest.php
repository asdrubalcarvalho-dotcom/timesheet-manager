<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

final class TimesheetPivotReportTest extends TenantTestCase
{
    private function seedTenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0:User,1:Technician,2:Project,3:Task,4:Location}
     */
    private function makeUserWithTimesheetDeps(string $name, string $email, string $role): array
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
        ]);
        $user->assignRole($role);

        $tech = Technician::create([
            'name' => $name,
            'email' => $email,
            'role' => 'technician',
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        $project = Project::create([
            'name' => "Project for {$name}",
            'description' => 'A',
            'status' => 'active',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'Task A',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'HQ',
            'country' => 'PRT',
            'city' => 'Lisbon',
            'address' => 'Main St',
            'postal_code' => '1000-000',
            'is_active' => true,
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        return [$user, $tech, $project, $task, $location];
    }

    public function test_pivot_for_elevated_user_sees_all_users_and_projects(): void
    {
        $this->seedTenant();

        [$manager] = $this->makeUserWithTimesheetDeps('Manager', 'manager@example.com', 'Manager');

        [$user1, $tech1, $projectA, $taskA, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

        $projectB = Project::create([
            'name' => 'Project B',
            'description' => 'B',
            'status' => 'active',
        ]);
        $taskB = Task::create([
            'project_id' => $projectB->id,
            'name' => 'Task B',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        [$user2, $tech2] = $this->makeUserWithTimesheetDeps('User 2', 'user2@example.com', 'Technician');

        // Ensure user2 belongs to both projects.
        ProjectMember::create([
            'project_id' => $projectA->id,
            'user_id' => $user2->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);
        ProjectMember::create([
            'project_id' => $projectB->id,
            'user_id' => $user2->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        // user1: Project A => 8h
        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $projectA->id,
            'task_id' => $taskA->id,
            'location_id' => $location->id,
            'date' => '2025-12-01',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'U1 A',
        ]);

        // user2: Project A => 6h
        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $projectA->id,
            'task_id' => $taskA->id,
            'location_id' => $location->id,
            'date' => '2025-12-01',
            'hours_worked' => 6,
            'status' => 'approved',
            'description' => 'U2 A',
        ]);

        // user2: Project B => 1.5h
        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $projectB->id,
            'task_id' => $taskB->id,
            'location_id' => $location->id,
            'date' => '2025-12-02',
            'hours_worked' => 1.5,
            'status' => 'approved',
            'description' => 'U2 B',
        ]);

        Sanctum::actingAs($manager);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot', [
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['user'], 'columns' => ['project']],
                'metrics' => ['hours'],
                'include' => ['row_totals' => true, 'column_totals' => true, 'grand_total' => true],
            ]);

        $res->assertOk();

        $this->assertSame('all', $res->json('meta.scoped'));

        $rows = $res->json('rows');
        $columns = $res->json('columns');
        $cells = $res->json('cells');

        $this->assertCount(2, $rows);
        $this->assertCount(2, $columns);
        $this->assertIsArray($cells);

        // Validate grand total: 8 + 6 + 1.5 = 15.5
        $this->assertEquals(15.5, $res->json('totals.grand.hours'));

        // Row totals
        $rowTotals = collect($res->json('totals.rows'))->keyBy('row_id');
        $this->assertEquals(8.0, $rowTotals[(string) $user1->id]['hours']);
        $this->assertEquals(7.5, $rowTotals[(string) $user2->id]['hours']);
    }

    public function test_pivot_is_scoped_for_regular_user_and_ignores_other_user_filter(): void
    {
        $this->seedTenant();

        [$user1, $tech1, $projectA, $taskA, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

        [$user2, $tech2] = $this->makeUserWithTimesheetDeps('User 2', 'user2@example.com', 'Technician');

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $projectA->id,
            'task_id' => $taskA->id,
            'location_id' => $location->id,
            'date' => '2025-12-01',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'U1',
        ]);

        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $projectA->id,
            'task_id' => $taskA->id,
            'location_id' => $location->id,
            'date' => '2025-12-01',
            'hours_worked' => 6,
            'status' => 'approved',
            'description' => 'U2',
        ]);

        Sanctum::actingAs($user1);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot', [
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['user'], 'columns' => ['project']],
                'filters' => ['user_id' => $user2->id],
            ]);

        $res->assertOk();
        $this->assertSame('self', $res->json('meta.scoped'));

        $rows = $res->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame((string) $user1->id, $rows[0]['id']);

        $grand = $res->json('totals.grand.hours');
        $this->assertEquals(8.0, $grand);
    }

    public function test_pivot_rejects_invalid_dimensions(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithTimesheetDeps('User 1', 'user1@example.com', 'Technician');

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot', [
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['task'], 'columns' => ['project']],
            ]);

        $res->assertStatus(422);
        $res->assertJsonValidationErrors(['dimensions.rows.0']);
    }
}
