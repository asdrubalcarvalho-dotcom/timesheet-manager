<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Demo Tenant',
            'slug' => 'demo',
            'owner_email' => 'demo@example.com',
            'status' => 'active',
            'plan' => 'standard',
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        tenancy()->initialize($this->tenant);
    }

    protected function tearDown(): void
    {
        tenancy()->end();

        parent::tearDown();
    }

    protected function tenantHeaders(): array
    {
        return ['X-Tenant' => $this->tenant->slug];
    }

    public function test_planning_routes_require_authentication(): void
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');

        $response->assertUnauthorized();
    }

    public function test_timesheet_status_cannot_be_forcefully_approved_via_update(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'Technician',
        ]);

        $project = Project::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Security Test Project',
            'description' => 'Test project',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);

        $task = Task::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'name' => 'Initial Task',
            'task_type' => 'maintenance',
            'is_active' => true,
        ]);

        $location = Location::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'HQ',
            'country' => 'PRT',
            'city' => 'Lisbon',
            'address' => 'Main St',
            'postal_code' => '1000-000',
            'is_active' => true,
        ]);

        $technician = Technician::create([
            'tenant_id' => $this->tenant->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'technician',
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        ProjectMember::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'project_role' => 'member',
            'expense_role' => 'member',
        ]);

        $timesheet = Timesheet::create([
            'tenant_id' => $this->tenant->id,
            'technician_id' => $technician->id,
            'project_id' => $project->id,
            'task_id' => $task->id,
            'location_id' => $location->id,
            'date' => now()->toDateString(),
            'hours_worked' => 8,
            'status' => 'draft',
        ]);

        $user->assignRole('Technician');

        Sanctum::actingAs($user);

        $response = $this->withHeaders($this->tenantHeaders())
            ->putJson("/api/timesheets/{$timesheet->id}", [
                'status' => 'approved',
            ]);

        $response->assertStatus(422);
        $this->assertSame('draft', $timesheet->fresh()->status);
    }
}
