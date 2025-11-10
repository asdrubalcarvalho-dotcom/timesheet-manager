<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Project;

class CompleteProjectMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cenários realistas de project membership

        $users = User::all();
        $projects = Project::all();

        if ($users->isEmpty() || $projects->isEmpty()) {
            $this->command->info('No users or projects found. Please run UserSeeder and ProjectSeeder first.');
            return;
        }

        // Cenário 1: Usuário pode ser Project Manager mas Expense Member
        // (Pode gerenciar timesheets mas não expenses)
        if ($users->count() >= 1 && $projects->count() >= 1) {
            ProjectMember::updateOrCreate(
                ['project_id' => 1, 'user_id' => 1],
                ['project_role' => 'manager', 'expense_role' => 'member']
            );
            $this->command->info('User 1 → Project 1: Project Manager + Expense Member');
        }

        // Cenário 2: Usuário pode ser Project Member mas Expense Manager  
        // (Só seus timesheets mas pode gerenciar expenses dos outros)
        if ($users->count() >= 2 && $projects->count() >= 1) {
            ProjectMember::updateOrCreate(
                ['project_id' => 1, 'user_id' => 2],
                ['project_role' => 'member', 'expense_role' => 'manager']
            );
            $this->command->info('User 2 → Project 1: Project Member + Expense Manager');
        }

        // Cenário 3: Usuário é Manager de ambos (controle total)
        if ($users->count() >= 3 && $projects->count() >= 2) {
            ProjectMember::updateOrCreate(
                ['project_id' => 2, 'user_id' => 3],
                ['project_role' => 'manager', 'expense_role' => 'manager']
            );
            $this->command->info('User 3 → Project 2: Project Manager + Expense Manager (Full Control)');
        }

        // Cenário 4: Usuário é Member de ambos (controle mínimo)
        if ($users->count() >= 4 && $projects->count() >= 2) {
            ProjectMember::updateOrCreate(
                ['project_id' => 2, 'user_id' => 4],
                ['project_role' => 'member', 'expense_role' => 'member']
            );
            $this->command->info('User 4 → Project 2: Project Member + Expense Member (Minimal Access)');
        }

        // Cenário 5: Múltiplas participações - User em vários projetos com roles diferentes
        if ($users->count() >= 2 && $projects->count() >= 2) {
            ProjectMember::updateOrCreate(
                ['project_id' => 2, 'user_id' => 1],
                ['project_role' => 'member', 'expense_role' => 'member']
            );
            $this->command->info('User 1 → Project 2: Project Member + Expense Member (Different from Project 1)');
        }

        // Cenário 6: Manager em um projeto, Member em outro
        if ($users->count() >= 2 && $projects->count() >= 2) {
            ProjectMember::updateOrCreate(
                ['project_id' => 2, 'user_id' => 2],
                ['project_role' => 'manager', 'expense_role' => 'member']
            );
            $this->command->info('User 2 → Project 2: Project Manager + Expense Member (Flipped from Project 1)');
        }

        $this->command->info('✅ Realistic project member scenarios created successfully!');
        $this->command->info('');
        $this->command->info('📊 Summary of scenarios:');
        $this->command->info('• Project Manager + Expense Member: Can approve timesheets, own expenses only');
        $this->command->info('• Project Member + Expense Manager: Own timesheets only, can approve expenses'); 
        $this->command->info('• Full Manager: Can approve both timesheets and expenses');
        $this->command->info('• Basic Member: Can only manage their own records');
        $this->command->info('• Multi-project: Same user with different roles in different projects');
    }
}