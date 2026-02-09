<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TimesheetAi;

use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\TimesheetAi\TimesheetPlanValidator;
use Spatie\Permission\Models\Permission;
use Tests\TenantTestCase;

class TimesheetPlanValidatorTest extends TenantTestCase
{
    public function test_validator_blocks_overlaps(): void
    {
        [$user, $technician, $project] = $this->seedProjectContext(true);

        $validator = app(TimesheetPlanValidator::class);

        $plan = [
            'prompt' => 'Create timesheets',
            'timezone' => 'UTC',
            'target_user_id' => $user->id,
            'technician_id' => $technician->id,
            'days' => [
                [
                    'date' => '2026-02-03',
                    'entries' => [
                        [
                            'project_id' => $project->id,
                            'project_name' => $project->name,
                            'start_time' => '09:00',
                            'end_time' => '12:00',
                        ],
                        [
                            'project_id' => $project->id,
                            'project_name' => $project->name,
                            'start_time' => '11:00',
                            'end_time' => '13:00',
                        ],
                    ],
                ],
            ],
        ];

        $result = $validator->validate($plan, $user, $technician, $user, false);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn($msg) => str_contains($msg, 'Overlapping time ranges detected on 2026-02-03.')));
    }

    public function test_validator_blocks_locked_dates(): void
    {
        [$user, $technician, $project, $task, $location] = $this->seedProjectContext(true);

        Timesheet::create([
            'technician_id' => $technician->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => '2026-02-04',
            'start_time' => '08:00',
            'end_time' => '09:00',
            'hours_worked' => 1.0,
            'description' => 'Approved entry',
            'status' => 'approved',
        ]);

        $validator = app(TimesheetPlanValidator::class);

        $plan = [
            'prompt' => 'Create timesheets',
            'timezone' => 'UTC',
            'target_user_id' => $user->id,
            'technician_id' => $technician->id,
            'days' => [
                [
                    'date' => '2026-02-04',
                    'entries' => [
                        [
                            'project_id' => $project->id,
                            'project_name' => $project->name,
                            'start_time' => '10:00',
                            'end_time' => '11:00',
                        ],
                    ],
                ],
            ],
        ];

        $result = $validator->validate($plan, $user, $technician, $user, false);

        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn($msg) => str_contains($msg, 'Date 2026-02-04 is locked')));
    }

    /**
     * @return array{0: User, 1: Technician, 2: Project, 3?: Task, 4?: Location}
     */
    private function seedProjectContext(bool $includeTaskAndLocation = false): array
    {
        Permission::firstOrCreate(['name' => 'create-timesheets', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('create-timesheets');

        $technician = Technician::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $project = Project::create([
            'name' => 'Project A',
            'description' => 'Demo',
            'status' => 'active',
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'none',
            'finance_role' => 'none',
        ]);

        if (!$includeTaskAndLocation) {
            return [$user, $technician, $project];
        }

        $task = Task::create([
            'project_id' => $project->id,
            'name' => 'General Work',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $location = Location::create([
            'name' => 'Default Location',
            'country' => 'PT',
            'city' => 'Lisbon',
            'is_active' => true,
        ]);

        $task->locations()->attach($location->id);

        return [$user, $technician, $project, $task, $location];
    }
}
