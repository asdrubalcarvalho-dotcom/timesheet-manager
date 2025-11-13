<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Remove constraint that blocked multiple timesheet entries 
     * for same technician+project+date, even when times don't overlap.
     * 
     * BEFORE: Could not create 11:00-12:00 AND 13:00-14:00 on same day
     * AFTER: Multiple non-overlapping entries allowed per day
     * 
     * Overlap validation still enforced by:
     * - StoreTimesheetRequest::hasTimeOverlap()
     * - idx_timesheets_no_exact_duplicates (exact time match prevention)
     */
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Remove overly restrictive unique constraint
            $table->dropUnique('timesheets_technician_id_project_id_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Restore original constraint (not recommended - blocks intercalated entries)
            $table->unique(['technician_id', 'project_id', 'date'], 'timesheets_technician_id_project_id_date_unique');
        });
    }
};
