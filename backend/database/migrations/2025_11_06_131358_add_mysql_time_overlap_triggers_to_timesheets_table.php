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
        // (Only if it exists - this might already be done in SQLite migration)
        try {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->dropUnique(['technician_id', 'project_id', 'date']);
            });
        } catch (\Exception $e) {
            // Constraint might not exist, that's ok
        }

        // Create MySQL triggers to prevent overlapping time entries
        // MySQL syntax is different from SQLite

        // Trigger for INSERT operations
        DB::unprepared('
            CREATE TRIGGER prevent_time_overlap_insert
            BEFORE INSERT ON timesheets
            FOR EACH ROW
            BEGIN
                DECLARE overlap_count INT DEFAULT 0;
                
                IF NEW.start_time IS NOT NULL AND NEW.end_time IS NOT NULL THEN
                    SELECT COUNT(*)
                    INTO overlap_count
                    FROM timesheets t
                    WHERE t.technician_id = NEW.technician_id
                    AND DATE(t.date) = DATE(NEW.date)
                    AND t.start_time IS NOT NULL
                    AND t.end_time IS NOT NULL
                    AND (
                        -- Check for overlap: new_start < existing_end AND existing_start < new_end
                        TIME(NEW.start_time) < TIME(t.end_time) AND TIME(t.start_time) < TIME(NEW.end_time)
                    );
                    
                    IF overlap_count > 0 THEN
                        SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Time overlap detected: Cannot create timesheet with overlapping time period";
                    END IF;
                END IF;
            END
        ');

        // Trigger for UPDATE operations  
        DB::unprepared('
            CREATE TRIGGER prevent_time_overlap_update
            BEFORE UPDATE ON timesheets
            FOR EACH ROW
            BEGIN
                DECLARE overlap_count INT DEFAULT 0;
                
                IF NEW.start_time IS NOT NULL AND NEW.end_time IS NOT NULL THEN
                    SELECT COUNT(*)
                    INTO overlap_count
                    FROM timesheets t
                    WHERE t.technician_id = NEW.technician_id
                    AND DATE(t.date) = DATE(NEW.date)
                    AND t.id != NEW.id -- Exclude the record being updated
                    AND t.start_time IS NOT NULL
                    AND t.end_time IS NOT NULL
                    AND (
                        -- Check for overlap: new_start < existing_end AND existing_start < new_end
                        TIME(NEW.start_time) < TIME(t.end_time) AND TIME(t.start_time) < TIME(NEW.end_time)
                    );
                    
                    IF overlap_count > 0 THEN
                        SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Time overlap detected: Cannot update timesheet with overlapping time period";
                    END IF;
                END IF;
            END
        ');

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
        // Drop the triggers
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_time_overlap_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS prevent_time_overlap_update');
        
        // Drop the unique index
        try {
            DB::statement('DROP INDEX idx_timesheets_no_exact_duplicates ON timesheets');
        } catch (\Exception $e) {
            // Index might not exist, that's ok
        }
        
        // Restore the old unique constraint (optional)
        try {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->unique(['technician_id', 'project_id', 'date']);
            });
        } catch (\Exception $e) {
            // That's ok if it fails
        }
    }
};
