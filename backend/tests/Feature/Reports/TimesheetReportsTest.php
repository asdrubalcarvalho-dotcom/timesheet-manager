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

    public function test_user_without_permission_gets_403(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser(false);

        Sanctum::actingAs($user);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/run', [
                'report' => 'timesheets_summary',
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31', 'status' => 'approved'],
                'group_by' => 'project',
            ])
            ->assertStatus(403);
    }

    public function test_invalid_report_name_returns_422(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/run', [
                'report' => 'invalid_report',
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'group_by' => 'project',
            ])
            ->assertStatus(422);
    }

    public function test_invalid_group_by_returns_422(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/run', [
                'report' => 'timesheets_calendar',
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'group_by' => 'project',
            ])
            ->assertStatus(422);
    }

    public function test_valid_report_returns_aggregated_result(): void
    {
        [$tenant, $user, $project] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/run', [
                'report' => 'timesheets_summary',
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31', 'status' => 'approved'],
                'group_by' => 'project',
            ]);

        $res->assertOk();

        $json = $res->json();
        $this->assertSame('timesheets_summary', $json['report']);
        $this->assertSame('project', $json['group_by']);
        $this->assertNotEmpty($json['data']);

        $first = $json['data'][0];
        $this->assertSame($project->id, $first['project']['id']);
        $this->assertSame(8.0, (float) $first['total_hours']);
    }

    public function test_export_returns_file_url(): void
    {
        [$tenant, $user] = $this->makeTenantAndUser(true);

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/export', [
                'report' => 'timesheets_summary',
                'filters' => ['from' => '2025-12-01', 'to' => '2025-12-31', 'status' => 'approved'],
                'group_by' => 'project',
                'format' => 'csv',
            ]);

        $res->assertOk()
            ->assertJsonStructure([
                'report',
                'format',
                'download_url',
                'expires_at',
            ]);

        $this->assertStringContainsString('/api/reports/download/', (string) $res->json('download_url'));
    }
}
