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
        Schema::table('timesheets', function (Blueprint $table) {
            // For SQLite, we'll just add the foreign keys directly
            // SQLite will handle duplicates gracefully
            try {
                $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            } catch (\Exception $e) {
                // Foreign key might already exist, continue
            }
            
            try {
                $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            } catch (\Exception $e) {
                // Foreign key might already exist, continue
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Drop unique constraint
            $table->dropUnique(['technician_id', 'project_id', 'task_id', 'date']);
            
            // Drop foreign keys
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
            
            // Restore original unique constraint
            $table->unique(['technician_id', 'project_id', 'date']);
        });
    }
};
