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
            // Check if columns don't exist before adding them
            if (!Schema::hasColumn('timesheets', 'task_id')) {
                $table->unsignedBigInteger('task_id')->after('project_id')->nullable();
            }
            if (!Schema::hasColumn('timesheets', 'location_id')) {
                $table->unsignedBigInteger('location_id')->after('task_id')->nullable();
            }
            
            // Add time fields only if they don't exist (start_time and end_time already exist)
            if (!Schema::hasColumn('timesheets', 'lunch_break')) {
                $table->integer('lunch_break')->after('end_time')->default(30)->comment('Lunch break in minutes');
            }
            
            // Add hour type enum
            if (!Schema::hasColumn('timesheets', 'hour_type')) {
                $table->enum('hour_type', [
                    'working', 'travel', 'standby', 'rest', 'on_scope', 'off_scope'
                ])->after('lunch_break')->default('working');
            }
            
            // Add additional fields from spec
            if (!Schema::hasColumn('timesheets', 'check_out_time')) {
                $table->time('check_out_time')->after('hour_type')->nullable();
            }
            if (!Schema::hasColumn('timesheets', 'machine_status')) {
                $table->enum('machine_status', ['online', 'offline'])->after('check_out_time')->nullable();
            }
            if (!Schema::hasColumn('timesheets', 'job_status')) {
                $table->enum('job_status', ['completed', 'ongoing'])->after('machine_status')->default('ongoing');
            }
        });
        
        // Now we need to populate default data before adding constraints
        // This will be handled in the seeder or a separate data migration
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Drop unique constraint
            $table->dropUnique(['technician_id', 'project_id', 'task_id', 'date']);
            
            // Remove added columns
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
            $table->dropColumn([
                'task_id', 
                'location_id', 
                'start_time', 
                'end_time', 
                'lunch_break', 
                'hour_type', 
                'check_out_time', 
                'machine_status', 
                'job_status'
            ]);
            
            // Restore original unique constraint
            $table->unique(['technician_id', 'project_id', 'date']);
        });
    }
};
