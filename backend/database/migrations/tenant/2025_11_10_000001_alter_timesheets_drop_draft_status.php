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
        if (Schema::hasColumn('timesheets', 'status') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE timesheets
                MODIFY COLUMN status ENUM('draft','submitted','approved','rejected','closed')
                NOT NULL DEFAULT 'draft'
            ");
        }

        if (Schema::hasColumn('timesheets', 'draft_reason')) {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->dropColumn('draft_reason');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('timesheets', 'status') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("
                ALTER TABLE timesheets
                MODIFY COLUMN status ENUM('draft','submitted','approved','rejected','closed')
                NOT NULL DEFAULT 'draft'
            ");
        }

        if (!Schema::hasColumn('timesheets', 'draft_reason')) {
            Schema::table('timesheets', function (Blueprint $table) {
                $table->string('draft_reason')->nullable()->after('status');
            });
        }
    }
};
