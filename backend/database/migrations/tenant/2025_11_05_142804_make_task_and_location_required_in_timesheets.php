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
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // First, ensure all existing timesheets have task_id and location_id
        DB::table('timesheets')
            ->whereNull('task_id')
            ->update(['task_id' => 1]); // Default to "General Work" task
            
        DB::table('timesheets')
            ->whereNull('location_id')
            ->update(['location_id' => 1]); // Default to "Default Location"
        
        // Drop foreign key constraints temporarily
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
        });
        
        // Make columns NOT NULL
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
        });
        
        // Recreate foreign key constraints
        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('restrict');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Drop foreign key constraints
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
        });
        
        // Make columns nullable
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
        });
        
        // Recreate original foreign key constraints
        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('set null');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
        });
    }
};
