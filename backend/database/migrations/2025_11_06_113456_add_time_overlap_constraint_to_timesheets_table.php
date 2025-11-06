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
        // Remove the old unique constraint that prevented multiple timesheets per project per day
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropUnique(['technician_id', 'project_id', 'date']);
        });

        // Create a unique index that includes time fields to prevent exact duplicates
        DB::statement('
            CREATE UNIQUE INDEX idx_timesheets_no_exact_duplicates 
            ON timesheets (technician_id, date, start_time, end_time) 
            WHERE start_time IS NOT NULL AND end_time IS NOT NULL
        ');

        // Create a database trigger to prevent overlapping time entries
        // This ensures data integrity even with concurrent requests
        DB::statement('
            CREATE TRIGGER prevent_time_overlap_insert
            BEFORE INSERT ON timesheets
            FOR EACH ROW
            WHEN NEW.start_time IS NOT NULL AND NEW.end_time IS NOT NULL
            BEGIN
                SELECT CASE
                    WHEN EXISTS (
                        SELECT 1 FROM timesheets t
                        WHERE t.technician_id = NEW.technician_id
                        AND DATE(t.date) = DATE(NEW.date)
                        AND t.start_time IS NOT NULL
                        AND t.end_time IS NOT NULL
                        AND (
                            -- Check for overlap: new_start < existing_end AND existing_start < new_end
                            NEW.start_time < t.end_time AND t.start_time < NEW.end_time
                        )
                    ) THEN
                        RAISE(ABORT, "Time overlap detected: Cannot create timesheet with overlapping time period")
                END;
            END
        ');

        // Create a similar trigger for updates
        DB::statement('
            CREATE TRIGGER prevent_time_overlap_update
            BEFORE UPDATE ON timesheets
            FOR EACH ROW
            WHEN NEW.start_time IS NOT NULL AND NEW.end_time IS NOT NULL
            BEGIN
                SELECT CASE
                    WHEN EXISTS (
                        SELECT 1 FROM timesheets t
                        WHERE t.technician_id = NEW.technician_id
                        AND DATE(t.date) = DATE(NEW.date)
                        AND t.id != NEW.id
                        AND t.start_time IS NOT NULL
                        AND t.end_time IS NOT NULL
                        AND (
                            -- Check for overlap: new_start < existing_end AND existing_start < new_end
                            NEW.start_time < t.end_time AND t.start_time < NEW.end_time
                        )
                    ) THEN
                        RAISE(ABORT, "Time overlap detected: Cannot update timesheet with overlapping time period")
                END;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the triggers
        DB::statement('DROP TRIGGER IF EXISTS prevent_time_overlap_insert');
        DB::statement('DROP TRIGGER IF EXISTS prevent_time_overlap_update');
        
        // Drop the unique index
        DB::statement('DROP INDEX IF EXISTS idx_timesheets_no_exact_duplicates');
        
        // Restore the original unique constraint
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unique(['technician_id', 'project_id', 'date']);
        });
    }
};
