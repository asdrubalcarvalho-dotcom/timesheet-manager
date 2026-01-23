<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = DB::connection('tenant');
        $dbName = $connection->getDatabaseName();

        // Drop legacy per-day uniqueness (multiple entries per day are allowed), but only if it exists.
        $exists = (int) (
            $connection->selectOne(
                "SELECT COUNT(1) AS c
                 FROM information_schema.statistics
                 WHERE table_schema = ?
                   AND table_name = 'timesheets'
                   AND index_name = ?",
                [$dbName, 'timesheets_technician_id_project_id_date_unique']
            )->c ?? 0
        ) > 0;

        if (!$exists) {
            return;
        }

        Schema::connection('tenant')->table('timesheets', function (Blueprint $table) {
            $table->dropUnique('timesheets_technician_id_project_id_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = DB::connection('tenant');
        $dbName = $connection->getDatabaseName();

        // Recreate the index only if it doesn't already exist.
        // Note: this can still fail if the tenant data contains duplicates for (technician_id, project_id, date).
        $exists = (int) (
            $connection->selectOne(
                "SELECT COUNT(1) AS c
                 FROM information_schema.statistics
                 WHERE table_schema = ?
                   AND table_name = 'timesheets'
                   AND index_name = ?",
                [$dbName, 'timesheets_technician_id_project_id_date_unique']
            )->c ?? 0
        ) > 0;

        if ($exists) {
            return;
        }

        Schema::connection('tenant')->table('timesheets', function (Blueprint $table) {
            $table->unique(
                ['technician_id', 'project_id', 'date'],
                'timesheets_technician_id_project_id_date_unique'
            );
        });
    }
};