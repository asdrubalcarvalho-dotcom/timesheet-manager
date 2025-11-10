<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class FinancePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create Finance permissions
        $permissions = [
            'review-finance-expenses' => 'Review expenses for finance approval',
            'approve-finance-expenses' => 'Final approval of expenses for payment',
            'mark-expenses-paid' => 'Mark expenses as paid',
        ];

        foreach ($permissions as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name],
                ['guard_name' => 'web']
            );
        }

        // Create Finance role if doesn't exist
        $financeRole = Role::firstOrCreate(
            ['name' => 'Finance'],
            ['guard_name' => 'web']
        );

        // Assign all finance permissions to Finance role
        $financeRole->givePermissionTo(array_keys($permissions));

        // Admin should have all finance permissions too
        $adminRole = Role::where('name', 'Admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo(array_keys($permissions));
        }

        $this->command->info('Finance permissions created successfully!');
        $this->command->info('Permissions: ' . implode(', ', array_keys($permissions)));
    }
}
