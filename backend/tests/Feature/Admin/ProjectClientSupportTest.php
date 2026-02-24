<?php

namespace Tests\Feature\Admin;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

class ProjectClientSupportTest extends TenantTestCase
{
    private function actingAsProjectManager(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $user = User::factory()->create([
            'role' => 'Admin',
        ]);

        $user->assignRole('Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_can_list_clients_for_project_form(): void
    {
        $this->actingAsProjectManager();

        Client::create(['name' => 'Zeta Corp']);
        Client::create(['name' => 'Alpha Group']);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/clients');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Alpha Group']);
        $response->assertJsonFragment(['name' => 'Zeta Corp']);
    }

    public function test_can_create_project_with_client_and_get_nested_client(): void
    {
        $this->actingAsProjectManager();

        $client = Client::create(['name' => 'ACME Industries']);

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/projects', [
                'name' => 'Client Project',
                'description' => 'Project linked to a client',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'status' => 'active',
                'client_id' => $client->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('client.name', 'ACME Industries');

        $this->assertDatabaseHas('projects', [
            'name' => 'Client Project',
            'client_id' => $client->id,
        ]);
    }

    public function test_can_update_project_to_remove_client(): void
    {
        $this->actingAsProjectManager();

        $client = Client::create(['name' => 'Globex']);
        $project = Project::create([
            'name' => 'Existing Project',
            'start_date' => now()->toDateString(),
            'status' => 'active',
            'client_id' => $client->id,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->putJson('/api/projects/' . $project->id, [
                'client_id' => null,
            ]);

        $response->assertOk()
            ->assertJsonPath('client_id', null)
            ->assertJsonPath('client', null);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'client_id' => null,
        ]);
    }

    public function test_rejects_invalid_client_id_on_project_create(): void
    {
        $this->actingAsProjectManager();

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/projects', [
                'name' => 'Invalid Client Project',
                'start_date' => now()->toDateString(),
                'status' => 'active',
                'client_id' => 999999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['client_id']);
    }
}
