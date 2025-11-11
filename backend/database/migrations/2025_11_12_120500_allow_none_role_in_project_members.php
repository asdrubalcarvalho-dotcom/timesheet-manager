<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE project_members MODIFY project_role ENUM('member','manager','none') DEFAULT 'member'");
        DB::statement("ALTER TABLE project_members MODIFY expense_role ENUM('member','manager','none') DEFAULT 'member'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE project_members MODIFY project_role ENUM('member','manager') DEFAULT 'member'");
        DB::statement("ALTER TABLE project_members MODIFY expense_role ENUM('member','manager') DEFAULT 'member'");
    }
};
