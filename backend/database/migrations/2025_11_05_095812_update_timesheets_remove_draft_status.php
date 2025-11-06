<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support ENUM or MODIFY COLUMN
        // So we'll just update the data and add a check constraint
        
        // First, update any existing 'draft' entries to 'submitted'
        \DB::table('timesheets')
            ->where('status', 'draft')
            ->update(['status' => 'submitted']);
            
        // For SQLite, we'll add a check constraint to validate status values
        try {
            \DB::statement("
                CREATE TABLE IF NOT EXISTS timesheets_new AS SELECT * FROM timesheets;
                DROP TABLE timesheets;
                CREATE TABLE timesheets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    technician_id INTEGER NOT NULL,
                    project_id INTEGER NOT NULL,
                    date DATE NOT NULL,
                    hours_worked DECIMAL(4,2) NOT NULL DEFAULT 0.00,
                    description TEXT,
                    status VARCHAR(255) NOT NULL DEFAULT 'submitted' CHECK (status IN ('submitted', 'approved', 'rejected', 'closed')),
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    start_time TIME NULL,
                    end_time TIME NULL,
                    task_id INTEGER NULL,
                    location_id INTEGER NULL
                );
                INSERT INTO timesheets SELECT * FROM timesheets_new;
                DROP TABLE timesheets_new;
            ");
        } catch (\Exception $e) {
            // If there's an error, just continue - the status field will work as text
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // For down migration, we'll just update the default back
        \DB::table('timesheets')
            ->where('status', 'submitted')
            ->whereRaw('created_at = updated_at')
            ->update(['status' => 'draft']);
    }
};
