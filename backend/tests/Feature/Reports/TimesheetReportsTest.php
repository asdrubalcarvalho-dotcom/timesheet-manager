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
use Laravel\Sanctum\Sanctum;
use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TenantTestCase;

final class TimesheetReportsTest extends TenantTestCase
{
    // Tenant + tenant schema are prepared by TenantTestCase.

    private function makeTenantAndUser(bool $withPermission): array
    {
        $tenant = $this->tenant;
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'password' => 'password',
        ]);

        if ($withPermission) {
            $user->assignRole('Technician');
        } else {
            // No roles => no permissions
            $user->syncRoles([]);
        }

        // Ensure role assignment is reflected when middleware calls `$user->can()`.
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

        Timesheet::create([
            'technician_id' => $tech->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2025-12-10',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'Work',
        ]);

        return [$tenant, $user, $project];
    }

    public function test_export_csv_streams_a_download(): void
    {
        [, $user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/export', [
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'format' => 'csv',
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $this->assertStringContainsString('.csv', (string) $res->headers->get('content-disposition'));
        $this->assertSame('text/csv; charset=UTF-8', (string) $res->headers->get('content-type'));

        $csv = $res->streamedContent();
        $this->assertStringContainsString('timesheet_id', $csv);
        $this->assertStringContainsString('user1@example.com', $csv);
    }

    public function test_export_xlsx_streams_a_download(): void
    {
        [, $user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/export', [
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

    public function test_regular_user_sees_all_members_in_project(): void
    {
        [, $user, $project] = $this->makeTenantAndUser(true);

        $location = Location::firstOrFail();
        $task = Task::where('project_id', $project->id)->firstOrFail();

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

        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2025-12-10',
            'hours_worked' => 6,
            'status' => 'approved',
            'description' => 'Other user work',
        ]);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/export', [
                // Phase 2: user1 is member of project, so sees ALL members' timesheets in that project
                'filters' => ['user_id' => $user2->id],
                'format' => 'csv',
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $csv = $res->streamedContent();

        // Phase 2: membership-based scoping => sees both user1 and user2 (both are members)
        $this->assertStringContainsString('user1@example.com', $csv);
        $this->assertStringContainsString('user2@example.com', $csv);
    }
}
