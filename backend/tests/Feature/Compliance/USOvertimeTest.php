<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Location;
use App\Models\Project;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

class USOvertimeTest extends TenantTestCase
{
    private function seedPermissions(User $user): void
    {
        Permission::firstOrCreate(['name' => 'view-timesheets', 'guard_name' => 'web']);
        $user->givePermissionTo('view-timesheets');
    }

    private function seedUserWithTechnician(): User
    {
        $user = User::factory()->create();

        Technician::create([
            'name' => 'Tech',
            'email' => $user->email,
            'role' => 'technician',
            'hourly_rate' => 50,
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function seedProjectTaskLocation(User $user): array
    {
        $project = Project::create([
            'name' => 'P1',
            'description' => null,
            'status' => 'active',
        ]);

        $project->memberRecords()->create([
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'T1',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'L1',
            'country' => 'US',
            'city' => 'NYC',
            'is_active' => true,
        ]);

        return [$project, $task, $location];
    }

    private function seedWeekHours(User $user, int $technicianId, int $projectId, int $taskId, int $locationId, array $dates, float $hoursPerDay): void
    {
        foreach ($dates as $date) {
            Timesheet::create([
                'technician_id' => $technicianId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'location_id' => $locationId,
                'date' => $date,
                'start_time' => '08:00:00',
                'end_time' => '17:00:00',
                'hours_worked' => $hoursPerDay,
                'status' => 'approved',
                'description' => 'work',
            ]);
        }
    }

    public function test_us_tenant_45h_yields_5h_overtime(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'region' => 'US-CA',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);

        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $dates = [
            '2026-01-12',
            '2026-01-13',
            '2026-01-14',
            '2026-01-15',
            '2026-01-16',
        ];

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, $dates, 9.0);

        $response = $this->getJson('/api/timesheets/summary?date=2026-01-14', $this->tenantHeaders());

        $response->assertOk();
        // CA v3.1 combination: daily OT exists, and weekly converts remaining regular hours.
        // 5 days x 9h = 45h total.
        // Daily: 5h OT1.5 + 40h regular.
        // Weekly excess 5h converts from remaining regular => 10h OT1.5 + 35h regular.
        $response->assertJson([
            'regular_hours' => 35.0,
            'overtime_hours' => 10.0,
            'overtime_rate' => 1.5,
            'workweek_start' => '2026-01-11',
        ]);
    }

    public function test_ny_tenant_45h_yields_5h_overtime_weekly_only(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'region' => 'US',
                'state' => 'NY',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);

        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $dates = [
            '2026-01-12',
            '2026-01-13',
            '2026-01-14',
            '2026-01-15',
            '2026-01-16',
        ];

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, $dates, 9.0);

        $response = $this->getJson('/api/timesheets/summary?date=2026-01-14', $this->tenantHeaders());

        $response->assertOk();
        $response->assertJson([
            'regular_hours' => 40.0,
            'overtime_hours' => 5.0,
            'overtime_rate' => 1.5,
            'workweek_start' => '2026-01-11',
        ]);
    }

    public function test_ny_tenant_10h_single_day_has_no_daily_overtime(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'region' => 'US',
                'state' => 'NY',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);
        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, ['2026-01-14'], 10.0);

        $response = $this->getJson('/api/timesheets/summary?date=2026-01-14', $this->tenantHeaders());

        $response->assertOk();
        $response->assertJson([
            'regular_hours' => 10.0,
            'overtime_hours' => 0.0,
            'overtime_rate' => 1.5,
            'workweek_start' => '2026-01-11',
        ]);
    }

    public function test_us_federal_fallback_applies_when_state_unknown(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'region' => 'US',
                'state' => 'TX',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);

        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $dates = [
            '2026-01-12',
            '2026-01-13',
            '2026-01-14',
            '2026-01-15',
            '2026-01-16',
        ];

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, $dates, 9.0);

        $response = $this->getJson('/api/timesheets/summary?date=2026-01-14', $this->tenantHeaders());

        $response->assertOk();
        $response->assertJson([
            'regular_hours' => 40.0,
            'overtime_hours' => 5.0,
            'overtime_rate' => 1.5,
            'workweek_start' => '2026-01-11',
        ]);
    }

    public function test_eu_tenant_45h_yields_0h_overtime(): void
    {
        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'pt_PT',
                'currency' => 'EUR',
                'region' => 'EU',
                'week_start' => 'monday',
            ],
        ])->saveQuietly();

        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);

        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $dates = [
            '2026-01-12',
            '2026-01-13',
            '2026-01-14',
            '2026-01-15',
            '2026-01-16',
        ];

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, $dates, 9.0);

        $response = $this->getJson('/api/timesheets/summary?date=2026-01-14', $this->tenantHeaders());

        $response->assertOk();
        $response->assertJson([
            'regular_hours' => 45.0,
            'overtime_hours' => 0.0,
            'overtime_rate' => 1.5,
            'workweek_start' => '2026-01-12',
        ]);
    }

    public function test_week_start_behavior_sunday_vs_monday(): void
    {
        $user = $this->seedUserWithTechnician();
        $this->seedPermissions($user);
        Sanctum::actingAs($user);

        [$project, $task, $location] = $this->seedProjectTaskLocation($user);
        $technicianId = (int) Technician::where('user_id', $user->id)->value('id');

        $this->seedWeekHours($user, $technicianId, $project->id, $task->id, $location->id, ['2026-01-11'], 8.0);

        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'en_US',
                'currency' => 'USD',
                'region' => 'US',
                'week_start' => 'sunday',
            ],
        ])->saveQuietly();

        $us = $this->getJson('/api/timesheets/summary?date=2026-01-11', $this->tenantHeaders());
        $us->assertOk();
        $us->assertJsonPath('workweek_start', '2026-01-11');

        $this->tenant->forceFill([
            'settings' => [
                'locale' => 'pt_PT',
                'currency' => 'EUR',
                'region' => 'EU',
                'week_start' => 'monday',
            ],
        ])->saveQuietly();

        $eu = $this->getJson('/api/timesheets/summary?date=2026-01-11', $this->tenantHeaders());
        $eu->assertOk();
        $eu->assertJsonPath('workweek_start', '2026-01-05');
    }
}
