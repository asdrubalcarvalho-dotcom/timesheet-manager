<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Expense;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Technician;
use App\Models\Timesheet;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TenantTestCase;

final class AiApprovalsQueryTest extends TenantTestCase
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

    public function test_user_with_both_permissions_gets_200_and_all_scope(): void
    {
        $this->seedTenant();

        [$user] = $this->makeUserWithDeps('Manager', 'manager.ai1@example.com', 'Manager', 'manager', 'manager');
        $user->givePermissionTo('approve-timesheets');
        $user->givePermissionTo('approve-expenses');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/approvals/query', [
                'question' => 'Onde estão os gargalos?',
                'context' => [
                    'range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
                ],
            ]);

        $res->assertOk();
        $res->assertJsonStructure([
            'answer',
            'highlights',
            'meta' => ['scoped', 'used_reports'],
        ]);

        $this->assertIsArray($res->json('highlights'));
        $this->assertLessThanOrEqual(3, count($res->json('highlights')));
        $this->assertSame('all', $res->json('meta.scoped'));
        $this->assertSame(['approvals_heatmap'], $res->json('meta.used_reports'));
    }

    public function test_user_with_only_timesheets_permission_requesting_expenses_gets_403(): void
    {
        $this->seedTenant();

        // Ensure Manager role does NOT grant approve-expenses for this test.
        $managerRole = Role::findByName('Manager');
        if ($managerRole->hasPermissionTo('approve-expenses')) {
            $managerRole->revokePermissionTo('approve-expenses');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$user] = $this->makeUserWithDeps('Manager', 'manager.ai2@example.com', 'Manager', 'manager', 'manager');
        $user->givePermissionTo('approve-timesheets');
        if ($user->hasPermissionTo('approve-expenses')) {
            $user->revokePermissionTo('approve-expenses');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/approvals/query', [
                'question' => 'Onde estão os gargalos?',
                'context' => [
                    'types' => ['expenses'],
                    'range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
                ],
            ]);

        $res->assertStatus(403);
    }

    public function test_user_with_only_expenses_permission_gets_partial_scope_and_mentions_only_expenses(): void
    {
        $this->seedTenant();

        // Ensure Manager role does NOT grant approve-timesheets for this test.
        $managerRole = Role::findByName('Manager');
        if ($managerRole->hasPermissionTo('approve-timesheets')) {
            $managerRole->revokePermissionTo('approve-timesheets');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        [$user] = $this->makeUserWithDeps('Manager', 'manager.ai3@example.com', 'Manager', 'manager', 'manager');
        $user->givePermissionTo('approve-expenses');
        if ($user->hasPermissionTo('approve-timesheets')) {
            $user->revokePermissionTo('approve-timesheets');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($user);

        $res = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/ai/approvals/query', [
                'question' => 'Onde estão os gargalos?',
                'context' => [
                    'types' => ['timesheets', 'expenses'],
                    'range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
                ],
            ]);

        $res->assertOk();
        $this->assertSame('partial', $res->json('meta.scoped'));
        $this->assertSame(['approvals_heatmap'], $res->json('meta.used_reports'));

        $answer = (string) $res->json('answer');
        $lower = strtolower($answer);
        $this->assertStringContainsString('expenses', $lower);
        $this->assertStringNotContainsString('timesheets', $lower);
    }
}
