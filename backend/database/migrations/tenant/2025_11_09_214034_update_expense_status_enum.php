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
        // MySQL doesn't support modifying ENUM directly, need to use raw SQL
        DB::statement("ALTER TABLE expenses MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected', 'finance_review', 'finance_approved', 'paid') NOT NULL DEFAULT 'draft'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Revert to old statuses
        DB::statement("ALTER TABLE expenses MODIFY COLUMN status ENUM('draft', 'submitted', 'approved', 'rejected') NOT NULL DEFAULT 'draft'");
    }
};
