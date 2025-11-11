<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Technician;

class TechnicianUserLinkerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Associar technicians existentes com users baseado no email
        $technicians = Technician::whereNull('user_id')->get();

        foreach ($technicians as $technician) {
            $user = User::where('email', $technician->email)->first();
            
            if ($user) {
                $technician->update(['user_id' => $user->id]);
                $this->command->info("Linked technician {$technician->name} with user {$user->name}");
            } else {
                $this->command->warn("No user found for technician {$technician->name} ({$technician->email})");
            }
        }

        $this->command->info('Finished linking technicians with users.');
    }
}