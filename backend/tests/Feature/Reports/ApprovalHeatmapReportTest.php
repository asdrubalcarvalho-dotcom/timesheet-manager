<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Expense;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TenantTestCase;

final class ApprovalHeatmapReportTest extends TenantTestCase
{
    private function seedTenant(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @return array{0:User,1:Technician,2:Project,3:Task,4:Location}
     */
    private function makeUserWithDeps(string $name, string $email, string $role, string $projectRole = 'member', string $expenseRole = 'member'): array
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
            'project_role' => $projectRole,
            'expense_role' => $expenseRole,
        ]);

        return [$user, $tech, $project, $task, $location];
    }

    private function setCreatedAt(object $model, string $ymd, string $time = '10:00:00'): void
    {
        $model->created_at = Carbon::parse("{$ymd} {$time}");
        $model->updated_at = Carbon::parse("{$ymd} {$time}");
        $model->saveQuietly();
    }

    public function test_owner_sees_heatmap_data(): void
    {
        $this->seedTenant();

        // Phase 2: Owner gets tenant-wide READ for approvals
        [$owner] = $this->makeUserWithDeps('Owner', 'owner@example.com', 'Owner');
        $owner->givePermissionTo('approve-timesheets');
        $owner->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$u1, $t1, $p1, $task, $loc] = $this->makeUserWithDeps('User 1', 'u1@example.com', 'Technician');

        $tsPending = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'task_id' => $task->id,
            'location_id' => $loc->id,
            'date' => '2026-01-02',
            'hours_worked' => 8,
            'status' => 'submitted',
            'description' => 'pending',
        ]);
        $this->setCreatedAt($tsPending, '2026-01-02');

        $tsApproved = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'task_id' => $task->id,
            'location_id' => $loc->id,
            // Avoid unique constraint (technician_id, project_id, date).
            // Heatmap groups by created_at day, so we keep created_at on 2026-01-02.
            'date' => '2026-01-03',
            'hours_worked' => 2,
            'status' => 'approved',
            'description' => 'approved',
        ]);
        $this->setCreatedAt($tsApproved, '2026-01-02', '11:00:00');

        $exPending = Expense::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'date' => '2026-01-02',
            'amount' => 10,
            'category' => 'meal',
            'description' => 'pending',
            'status' => 'submitted',
        ]);
        $this->setCreatedAt($exPending, '2026-01-02', '12:00:00');

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => true, 'expenses' => true],
            ]);

        $res->assertOk();
        $this->assertSame('2026-01-01', $res->json('meta.from'));
        $this->assertSame('2026-01-31', $res->json('meta.to'));

        $day = $res->json('days.2026-01-02');
        $this->assertIsArray($day);
        $this->assertSame(1, $day['timesheets']['pending']);
        $this->assertSame(1, $day['timesheets']['approved']);
        $this->assertSame(1, $day['expenses']['pending']);
        $this->assertSame(0, $day['expenses']['approved']);
        $this->assertSame(2, $day['total_pending']);
    }

    public function test_owner_sees_heatmap_data_tenant_wide(): void
    {
        $this->seedTenant();

        // Phase 2: Owner gets tenant-wide READ for approvals
        [$owner, , $project, $task, $loc] = $this->makeUserWithDeps(
            'Owner',
            'owner@example.com',
            'Owner'
        );
        $owner->givePermissionTo('approve-timesheets');
        $owner->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$u1, $t1] = $this->makeUserWithDeps('User 1', 'u1@example.com', 'Technician');

        // Create a second project that the manager is NOT a member/manager of.
        $otherProject = Project::create([
            'name' => 'Other Project',
            'description' => 'B',
            'status' => 'active',
        ]);
        $otherTask = Task::create([
            'project_id' => $otherProject->id,
            'name' => 'Other Task',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $tsPending = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $loc->id,
            'date' => '2026-01-03',
            'hours_worked' => 1,
            'status' => 'submitted',
            'description' => 'pending',
        ]);
        $this->setCreatedAt($tsPending, '2026-01-03');

        $tsOtherPending = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $otherProject->id,
            'task_id' => $otherTask->id,
            'location_id' => $loc->id,
            // Avoid unique constraint on (technician_id, project_id, date).
            'date' => '2026-01-04',
            'hours_worked' => 1,
            'status' => 'submitted',
            'description' => 'pending other',
        ]);
        $this->setCreatedAt($tsOtherPending, '2026-01-04');

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => true, 'expenses' => true],
            ]);

        $res->assertOk();
        $day = $res->json('days.2026-01-03');
        $this->assertSame(1, $day['timesheets']['pending']);
        $this->assertSame(1, $day['total_pending']);

        $day2 = $res->json('days.2026-01-04');
        $this->assertIsArray($day2);
        $this->assertSame(1, $day2['timesheets']['pending']);
    }

    public function test_regular_user_gets_403(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('User', 'user@example.com', 'Technician');

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => true, 'expenses' => true],
            ]);

        $res->assertStatus(403);
    }

    public function test_only_timesheets_included(): void
    {
        $this->seedTenant();

        [$owner] = $this->makeUserWithDeps('Owner', 'owner2@example.com', 'Owner');
        $owner->givePermissionTo('approve-timesheets');
        $owner->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [$u1, $t1, $p1, $task, $loc] = $this->makeUserWithDeps('User 1', 'u2@example.com', 'Technician');

        $tsPending = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'task_id' => $task->id,
            'location_id' => $loc->id,
            'date' => '2026-01-04',
            'hours_worked' => 1,
            'status' => 'submitted',
            'description' => 'pending',
        ]);
        $this->setCreatedAt($tsPending, '2026-01-04');

        $exPending = Expense::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'date' => '2026-01-05',
            'amount' => 10,
            'category' => 'meal',
            'description' => 'pending',
            'status' => 'submitted',
        ]);
        $this->setCreatedAt($exPending, '2026-01-05');

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => true, 'expenses' => false],
            ]);

        $res->assertOk();

        // Timesheet day appears.
        $day = $res->json('days.2026-01-04');
        $this->assertSame(1, $day['timesheets']['pending']);
        $this->assertSame(0, $day['expenses']['pending']);
        $this->assertSame(1, $day['total_pending']);

        // Expense-only day should not appear.
        $this->assertNull($res->json('days.2026-01-05'));
    }

    public function test_only_expenses_included(): void
    {
        $this->seedTenant();

        [$owner] = $this->makeUserWithDeps('Owner', 'owner3@example.com', 'Owner');
        $owner->givePermissionTo('approve-timesheets');
        $owner->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        [$u1, $t1, $p1] = $this->makeUserWithDeps('User 1', 'u3@example.com', 'Technician');

        $tsPending = Timesheet::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'task_id' => Task::where('project_id', $p1->id)->first()->id,
            'location_id' => Location::first()->id,
            'date' => '2026-01-06',
            'hours_worked' => 1,
            'status' => 'submitted',
            'description' => 'pending',
        ]);
        $this->setCreatedAt($tsPending, '2026-01-06');

        $exPending = Expense::create([
            'technician_id' => $t1->id,
            'project_id' => $p1->id,
            'date' => '2026-01-07',
            'amount' => 10,
            'category' => 'meal',
            'description' => 'pending',
            'status' => 'submitted',
        ]);
        $this->setCreatedAt($exPending, '2026-01-07');

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => false, 'expenses' => true],
            ]);

        $res->assertOk();

        // Expense day appears.
        $day = $res->json('days.2026-01-07');
        $this->assertSame(1, $day['expenses']['pending']);
        $this->assertSame(0, $day['timesheets']['pending']);
        $this->assertSame(1, $day['total_pending']);

        // Timesheet-only day should not appear.
        $this->assertNull($res->json('days.2026-01-06'));
    }

    public function test_empty_range_returns_empty_days(): void
    {
        $this->seedTenant();

        [$owner] = $this->makeUserWithDeps('Owner', 'owner4@example.com', 'Owner');
        $owner->givePermissionTo('approve-timesheets');
        $owner->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($owner);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/approvals/heatmap', [
                'range' => ['from' => '2026-01-01', 'to' => '2026-01-31'],
                'include' => ['timesheets' => true, 'expenses' => true],
            ]);

        $res->assertOk();
        $this->assertSame([], $res->json('days'));
    }
}
