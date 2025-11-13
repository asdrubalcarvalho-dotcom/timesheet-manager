<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
            
            // Modify columns to be explicitly nullable
            $table->unsignedBigInteger('task_id')->nullable()->change();
            $table->unsignedBigInteger('location_id')->nullable()->change();
            
            // Re-add foreign key constraints
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('set null');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            // Drop foreign key constraints
            $table->dropForeign(['task_id']);
            $table->dropForeign(['location_id']);
            
            // Revert columns back (but this might fail if there are null values)
            $table->unsignedBigInteger('task_id')->nullable(false)->change();
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            
            // Re-add foreign key constraints
            $table->foreign('task_id')->references('id')->on('tasks')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
        });
    }
};
