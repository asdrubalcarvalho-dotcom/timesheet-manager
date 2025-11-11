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

        // Create a unique index that includes time fields to prevent exact duplicates
        try {
            DB::statement('
                CREATE UNIQUE INDEX idx_timesheets_no_exact_duplicates 
                ON timesheets (technician_id, date, start_time, end_time)
            ');
        } catch (\Exception $e) {
            // Índice já existe, ignorar
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
                // Index might not exist, ignore.
            }

            try {
                $table->unique(['technician_id', 'project_id', 'date'], 'timesheets_technician_project_date_unique');
            } catch (\Throwable $e) {
                // Constraint might already exist, ignore.
            }
        });
    }
};
