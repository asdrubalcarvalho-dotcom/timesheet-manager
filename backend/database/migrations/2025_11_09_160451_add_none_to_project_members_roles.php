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
        // MySQL doesn't support direct ALTER of ENUM, so we need to use raw SQL
        DB::statement("ALTER TABLE project_members MODIFY COLUMN project_role ENUM('none', 'member', 'manager') DEFAULT 'member'");
        DB::statement("ALTER TABLE project_members MODIFY COLUMN expense_role ENUM('none', 'member', 'manager') DEFAULT 'member'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE project_members MODIFY COLUMN project_role ENUM('member', 'manager') DEFAULT 'member'");
        DB::statement("ALTER TABLE project_members MODIFY COLUMN expense_role ENUM('member', 'manager') DEFAULT 'member'");
    }
};
