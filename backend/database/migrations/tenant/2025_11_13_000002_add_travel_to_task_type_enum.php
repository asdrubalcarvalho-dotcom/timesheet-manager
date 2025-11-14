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
        DB::statement("ALTER TABLE tasks MODIFY COLUMN task_type ENUM(
            'retrofit',
            'inspection',
            'commissioning',
            'maintenance',
            'installation',
            'testing',
            'documentation',
            'training',
            'travel'
        ) NOT NULL DEFAULT 'maintenance'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE tasks MODIFY COLUMN task_type ENUM(
            'retrofit',
            'inspection',
            'commissioning',
            'maintenance',
            'installation',
            'testing',
            'documentation',
            'training'
        ) NOT NULL DEFAULT 'maintenance'");
    }
};
