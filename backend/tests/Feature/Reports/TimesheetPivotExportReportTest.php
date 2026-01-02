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

final class TimesheetPivotExportReportTest extends TenantTestCase
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

    public function test_pivot_export_csv_streams_a_download(): void
    {
        $this->seedTenant();

        [$manager] = $this->makeUserWithTimesheetDeps('Manager', 'manager@example.com', 'Manager');
        [$user, $tech, $project, $task, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

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

        Sanctum::actingAs($manager);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot/export', [
                'format' => 'csv',
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['user'], 'columns' => ['project']],
                'metrics' => ['hours'],
                'include' => ['row_totals' => true, 'column_totals' => true, 'grand_total' => true],
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $this->assertSame('text/csv; charset=UTF-8', (string) $res->headers->get('content-type'));
        $this->assertStringContainsString(
            'attachment; filename="timesheets_pivot_2025-12-01_2025-12-31.csv"',
            (string) $res->headers->get('content-disposition')
        );

        $csv = $res->streamedContent();

        // Header row includes row dimension label and at least one column label.
        $this->assertStringContainsString('User', $csv);
        $this->assertStringContainsString($project->name, $csv);

        // At least one numeric cell.
        $this->assertMatchesRegularExpression('/\b8(\.0+)?\b/', $csv);
    }

    public function test_pivot_export_xlsx_streams_a_download(): void
    {
        $this->seedTenant();

        [$manager] = $this->makeUserWithTimesheetDeps('Manager', 'manager@example.com', 'Manager');
        [, $tech, $project, $task, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );

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

        Sanctum::actingAs($manager);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot/export', [
                'format' => 'xlsx',
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['user'], 'columns' => ['project']],
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $res->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'attachment; filename="timesheets_pivot_2025-12-01_2025-12-31.xlsx"',
            (string) $res->headers->get('content-disposition')
        );

        $bin = $res->streamedContent();
        $this->assertSame('PK', substr($bin, 0, 2));
    }

    public function test_pivot_export_scoping_is_enforced_for_regular_user(): void
    {
        $this->seedTenant();

        [$user1, $tech1, $project, $task, $location] = $this->makeUserWithTimesheetDeps(
            'User 1',
            'user1@example.com',
            'Technician'
        );
        [$user2, $tech2] = $this->makeUserWithTimesheetDeps('User 2', 'user2@example.com', 'Technician');

        Timesheet::create([
            'technician_id' => $tech1->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2025-12-10',
            'hours_worked' => 8,
            'status' => 'approved',
            'description' => 'U1',
        ]);

        Timesheet::create([
            'technician_id' => $tech2->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2025-12-10',
            'hours_worked' => 6,
            'status' => 'approved',
            'description' => 'U2',
        ]);

        Sanctum::actingAs($user1);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/reports/timesheets/pivot/export', [
                'format' => 'csv',
                'period' => 'month',
                'range' => ['from' => '2025-12-01', 'to' => '2025-12-31'],
                'dimensions' => ['rows' => ['user'], 'columns' => ['project']],
                'filters' => ['user_id' => $user2->id],
                'include' => ['row_totals' => true, 'column_totals' => true, 'grand_total' => true],
            ]);

        $this->assertTrue($res->isOk(), (string) $res->getContent());

        $csv = $res->streamedContent();

        // user2 label must not appear in export.
        $this->assertStringContainsString('User 1', $csv);
        $this->assertStringNotContainsString('User 2', $csv);

        // Totals reflect only user1 (8h).
        $this->assertMatchesRegularExpression('/\b8(\.0+)?\b/', $csv);
    }
}
