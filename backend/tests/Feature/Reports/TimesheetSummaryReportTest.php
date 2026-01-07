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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

final class TimesheetSummaryReportTest extends TenantTestCase
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

    public function test_summary_by_user_day_membership_scoping(): void
    {
        $this->seedTenant();

        [$user1, $tech1, $project1, $task1, $location1] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

        [$user2, $tech2] = $this->makeUserWithTimesheetDeps('User 2', 'user2@example.com', 'Technician');

        // Phase 2: Add user2 to project1 so user1 sees both
        ProjectMember::create([
            'project_id' => $project1->id,
            'user_id' => $user2->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-01',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'Work 1',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-02',
            'hours_worked' => 4,
            'status' => 'submitted',
            'description' => 'Work 2',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-03',
            'hours_worked' => 2,
            'status' => 'rejected',
            'description' => 'Work 3',
        ]);

        // Phase 2: user2 is now member, so their timesheet will appear
        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-01',
            'hours_worked' => 6,
            'status' => 'approved',
            'description' => 'Other user work',
        ]);

        Sanctum::actingAs($user1);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/summary', [
                'from' => '2025-12-01',
                'to' => '2025-12-03',
                'group_by' => ['user'],
                'period' => 'day',
            ]);

        $res->assertOk();

        $rows = $res->json('rows');
        $this->assertIsArray($rows);
        // Phase 2: Now sees 4 rows (3 for user1 + 1 for user2 on 2025-12-01)
        $this->assertCount(4, $rows);

        $byPeriod = collect($rows)->groupBy('period');

        // 2025-12-01: Both user1 (8h) and user2 (6h)
        $this->assertCount(2, $byPeriod['2025-12-01']);
        $dec01 = collect($byPeriod['2025-12-01'])->keyBy('user_id');
        $this->assertSame(480, $dec01[$user1->id]['total_minutes']);
        $this->assertSame(360, $dec01[$user2->id]['total_minutes']);

        // 2025-12-02: Only user1
        $this->assertCount(1, $byPeriod['2025-12-02']);
        $this->assertSame(240, $byPeriod['2025-12-02'][0]['total_minutes']);

        // 2025-12-03: Only user1
        $this->assertCount(1, $byPeriod['2025-12-03']);
        $this->assertSame(120, $byPeriod['2025-12-03'][0]['total_minutes']);
    }

    public function test_summary_for_owner_sees_all_users_and_projects(): void
    {
        $this->seedTenant();

        // Phase 2: Owner gets tenant-wide READ
        [$owner] = $this->makeUserWithTimesheetDeps('Owner', 'owner@example.com', 'Owner');

        [$user1, $tech1, $projectA, $taskA, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

        // Create a second project for user2
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

        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'password' => 'password',
        ]);
        $user2->assignRole('Technician');

        $tech2 = Technician::create([
            'name' => 'User 2',
            'email' => $user2->email,
            'role' => 'technician',
            'user_id' => $user2->id,
            'is_active' => true,
        ]);

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

        // Project A: user1 + user2
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

        // Project B: user2 only
        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $projectB->id,
            'task_id' => $taskB->id,
            'location_id' => $location->id,
            'date' => '2025-12-02',
            'hours_worked' => 1,
            'status' => 'submitted',
            'description' => 'U2 B',
        ]);

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/summary', [
                'from' => '2025-12-01',
                'to' => '2025-12-31',
                'group_by' => ['project'],
                'period' => 'month',
            ]);

        $res->assertOk();

        $rows = $res->json('rows');
        $this->assertIsArray($rows);
        $this->assertCount(2, $rows);

        $byProject = collect($rows)->keyBy('project_name');

        $this->assertSame('2025-12', $byProject['Project B']['period']);
        $this->assertSame(60, $byProject['Project B']['total_minutes']);
        $this->assertSame(0, $byProject['Project B']['approved_minutes']);
        $this->assertSame(60, $byProject['Project B']['pending_minutes']);
        $this->assertSame(0, $byProject['Project B']['rejected_minutes']);
        $this->assertSame(1, $byProject['Project B']['total_entries']);

        $this->assertSame('2025-12', $byProject["Project for User 1"]['period']);
        $this->assertSame(840, $byProject["Project for User 1"]['total_minutes']);
        $this->assertSame(840, $byProject["Project for User 1"]['approved_minutes']);
        $this->assertSame(0, $byProject["Project for User 1"]['pending_minutes']);
        $this->assertSame(0, $byProject["Project for User 1"]['rejected_minutes']);
        $this->assertSame(2, $byProject["Project for User 1"]['total_entries']);
    }

    public function test_summary_week_grouped_by_user_and_project_returns_correct_aggregates(): void
    {
        $this->seedTenant();

        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'password',
        ]);
        $owner->assignRole('Owner');

        [$user1, $tech1, $project1, $task1, $location1] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

        [$user2, $tech2, $project2, $task2, $location2] = $this->makeUserWithTimesheetDeps(
            'User 2',
            'user2@example.com',
            'Technician'
        );

        // Ensure both users are members of both projects (safe for policy changes).
        ProjectMember::firstOrCreate([
            'project_id' => $project1->id,
            'user_id' => $user2->id,
        ], [
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        ProjectMember::firstOrCreate([
            'project_id' => $project2->id,
            'user_id' => $user1->id,
        ], [
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        // Two ISO weeks:
        // - week1: 2025-12-15 .. 2025-12-21
        // - week2: 2025-12-22 .. 2025-12-28
        $week1Key = Carbon::parse('2025-12-15')->format('o-\\WW');
        $week2Key = Carbon::parse('2025-12-22')->format('o-\\WW');

        // Week 1 groups
        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-15',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'U1 P1 approved',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project2->id,
            'task_id' => $task2->id,
            'location_id' => $location2->id,
            'date' => '2025-12-16',
            'hours_worked' => 2,
            'status' => 'submitted',
            'description' => 'U1 P2 pending',
        ]);

        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-17',
            'hours_worked' => 1,
            'status' => 'rejected',
            'description' => 'U2 P1 rejected',
        ]);

        // Week 2 groups (mixed statuses for same (user, project, period) aggregate)
        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-22',
            'hours_worked' => 3,
            'status' => 'approved',
            'description' => 'U1 P1 approved',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-23',
            'hours_worked' => 2,
            'status' => 'submitted',
            'description' => 'U1 P1 pending',
        ]);

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project1->id,
            'task_id' => $task1->id,
            'location_id' => $location1->id,
            'date' => '2025-12-24',
            'hours_worked' => 1,
            'status' => 'rejected',
            'description' => 'U1 P1 rejected',
        ]);

        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $project2->id,
            'task_id' => $task2->id,
            'location_id' => $location2->id,
            'date' => '2025-12-25',
            'hours_worked' => 4,
            'status' => 'approved',
            'description' => 'U2 P2 approved',
        ]);

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/summary', [
                'from' => '2025-12-15',
                'to' => '2025-12-25',
                'group_by' => ['user', 'project'],
                'period' => 'week',
            ]);

        $res->assertOk();

        $rows = $res->json('rows');
        $this->assertIsArray($rows);

        // Expected groups:
        // week1: (u1,p1), (u1,p2), (u2,p1)
        // week2: (u1,p1), (u2,p2)
        $this->assertCount(5, $rows);

        $periods = collect($rows)->pluck('period')->unique()->values()->all();
        $this->assertContains($week1Key, $periods);
        $this->assertContains($week2Key, $periods);

        // Lock down one high-signal row: week2 + (user1, project1)
        $row = collect($rows)->first(function (array $r) use ($week2Key, $user1, $project1) {
            return $r['period'] === $week2Key
                && $r['user_id'] === $user1->id
                && $r['project_id'] === $project1->id;
        });

        $this->assertIsArray($row);
        $this->assertSame(360, $row['total_minutes']);
        $this->assertSame(180, $row['approved_minutes']);
        $this->assertSame(120, $row['pending_minutes']);
        $this->assertSame(60, $row['rejected_minutes']);
        $this->assertSame(3, $row['total_entries']);
    }
}
