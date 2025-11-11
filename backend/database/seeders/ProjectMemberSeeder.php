<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Project;

class ProjectMemberSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get some users and projects to create example relationships
        $users = User::all();
        $projects = Project::all();

        if ($users->isEmpty() || $projects->isEmpty()) {
            $this->command->info('No users or projects found. Please run UserSeeder and ProjectSeeder first.');
            return;
        }

        // Example project member assignments
        $assignments = [
            // Project 1 assignments
            [
                'project_id' => 1,
                'user_id' => 1, // First user as project manager and expense member
                'project_role' => 'manager',
                'expense_role' => 'member'
            ],
            [
                'project_id' => 1,
                'user_id' => 2, // Second user as project member and expense manager
                'project_role' => 'member',
                'expense_role' => 'manager'
            ],
            [
                'project_id' => 1,
                'user_id' => 3, // Third user as regular member for both
                'project_role' => 'member',
                'expense_role' => 'member'
            ],

            // Project 2 assignments (if exists)
            [
                'project_id' => 2,
                'user_id' => 1, // First user as member for both
                'project_role' => 'member',
                'expense_role' => 'member'
            ],
            [
                'project_id' => 2,
                'user_id' => 2, // Second user as manager for both
                'project_role' => 'manager',
                'expense_role' => 'manager'
            ],
        ];

        foreach ($assignments as $assignment) {
            // Only create if the project and user exist
            if (Project::find($assignment['project_id']) && User::find($assignment['user_id'])) {
                ProjectMember::updateOrCreate(
                    [
                        'project_id' => $assignment['project_id'],
                        'user_id' => $assignment['user_id']
                    ],
                    [
                        'project_role' => $assignment['project_role'],
                        'expense_role' => $assignment['expense_role']
                    ]
                );

                $this->command->info("Assigned user {$assignment['user_id']} to project {$assignment['project_id']} with roles: project={$assignment['project_role']}, expense={$assignment['expense_role']}");
            }
        }
    }
}