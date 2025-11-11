<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Removido dropUnique para evitar erro de constraint em MySQL

        // Create MySQL triggers to prevent overlapping time entries
        // MySQL syntax is different from SQLite

        // Triggers removidos para evitar erro de privilégio SUPER no MySQL

        // Create a unique index to prevent exact duplicates (optional but recommended)
        try {
            DB::statement('
                CREATE UNIQUE INDEX idx_timesheets_no_exact_duplicates 
                ON timesheets (technician_id, date, start_time, end_time)
            ');
        } catch (\Exception $e) {
            // Index might already exist, that's ok
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            try {
                $table->dropUnique('idx_timesheets_no_exact_duplicates');
            } catch (\Throwable $e) {
                // Ignore missing index
            }

            try {
                $table->unique(['technician_id', 'project_id', 'date'], 'timesheets_technician_project_date_unique');
            } catch (\Throwable $e) {
                // Ignore if already present
            }
        });
    }
};
