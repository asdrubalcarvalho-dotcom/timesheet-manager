<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        // Expenses
        $this->dropForeignIfExists('expenses', 'technician_id');
        $this->dropForeignIfExists('expenses', 'project_id');
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'technician_id')) {
                $table->foreign('technician_id')->references('id')->on('technicians')->onDelete('restrict');
            }
            if (Schema::hasColumn('expenses', 'project_id')) {
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('restrict');
            }
        });

        // Project members
        $this->dropForeignIfExists('project_members', 'project_id');
        $this->dropForeignIfExists('project_members', 'user_id');
        Schema::table('project_members', function (Blueprint $table) {
            if (Schema::hasColumn('project_members', 'project_id')) {
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('restrict');
            }
            if (Schema::hasColumn('project_members', 'user_id')) {
                $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            }
        });

        // Tasks
        $this->dropForeignIfExists('tasks', 'project_id');
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'project_id')) {
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('restrict');
            }
        });

        // Timesheets
        $this->dropForeignIfExists('timesheets', 'technician_id');
        $this->dropForeignIfExists('timesheets', 'project_id');
        $this->dropForeignIfExists('timesheets', 'task_id');
        $this->dropForeignIfExists('timesheets', 'location_id');
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'technician_id')) {
                $table->foreign('technician_id')->references('id')->on('technicians')->onDelete('restrict');
            }
            if (Schema::hasColumn('timesheets', 'project_id')) {
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('restrict');
            }
            if (Schema::hasColumn('timesheets', 'task_id')) {
                $table->foreign('task_id')->references('id')->on('tasks')->onDelete('restrict');
            }
            if (Schema::hasColumn('timesheets', 'location_id')) {
                $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
            }
        });

        // Travel segments
        $this->dropForeignIfExists('travel_segments', 'technician_id');
        $this->dropForeignIfExists('travel_segments', 'project_id');
        Schema::table('travel_segments', function (Blueprint $table) {
            if (Schema::hasColumn('travel_segments', 'technician_id')) {
                $table->foreign('technician_id')->references('id')->on('technicians')->onDelete('restrict');
            }
            if (Schema::hasColumn('travel_segments', 'project_id')) {
                $table->foreign('project_id')->references('id')->on('projects')->onDelete('restrict');
            }
        });

        /*
         * Verification (expected to fail now):
         * - DELETE FROM projects WHERE id=<id> when tasks/timesheets/expenses/travel_segments reference the project
         * - DELETE FROM technicians WHERE id=<id> when timesheets/expenses/travel_segments reference the technician
         * - DELETE FROM locations WHERE id=<id> when timesheets reference the location
         * - DELETE FROM users WHERE id=<id> when project_members reference the user
         */
    }

    public function down(): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() !== 'mysql') {
            return;
        }

        // Keep RESTRICT semantics in down() to avoid reintroducing unsafe cascades
        $this->up();
    }

    private function dropForeignIfExists(string $table, string $column): void
    {
        $connection = Schema::getConnection();
        $connectionName = $connection->getName();
        $schema = $connection->getDatabaseName();
        $prefixedTable = $connection->getTablePrefix() . $table;

        $constraint = DB::connection($connectionName)
            ->table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', $schema)
            ->where('TABLE_NAME', $prefixedTable)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->orderByDesc('CONSTRAINT_NAME')
            ->first();

        if ($constraint && isset($constraint->CONSTRAINT_NAME)) {
            DB::connection($connectionName)
                ->statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $prefixedTable, $constraint->CONSTRAINT_NAME));
        }
    }
};
