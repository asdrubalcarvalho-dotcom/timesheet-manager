<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Project;

class AssignProjectManagersSeeder extends Seeder
{
    /**
     * Run the database seeder.
     */
    public function run(): void
    {
        // Obter todos os managers
        $managers = User::role('Manager')->get();
        
        if ($managers->isEmpty()) {
            $this->command->info('Nenhum manager encontrado. Criando um manager de exemplo...');
            
            // Criar um manager de exemplo se não existir
            $manager = User::create([
                'name' => 'Manager de Projeto',
                'email' => 'manager@timesheet.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]);
            
            $manager->assignRole('Manager');
            $managers = collect([$manager]);
        }
        
        // Obter todos os projetos sem manager
        $projectsWithoutManager = Project::whereNull('manager_id')->get();
        
        if ($projectsWithoutManager->isEmpty()) {
            $this->command->info('Todos os projetos já têm managers atribuídos.');
            return;
        }
        
        // Atribuir managers aos projetos de forma rotativa
        $managerIndex = 0;
        foreach ($projectsWithoutManager as $project) {
            $project->update([
                'manager_id' => $managers[$managerIndex % $managers->count()]->id
            ]);
            
            $this->command->info("Projeto '{$project->name}' atribuído ao manager '{$managers[$managerIndex % $managers->count()]->name}'");
            $managerIndex++;
        }
        
        $this->command->info('Atribuição de managers aos projetos concluída!');
    }
}
