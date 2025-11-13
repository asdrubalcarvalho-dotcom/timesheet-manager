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
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('worker_id')->nullable()->unique()->after('user_id');
            $table->string('worker_name')->nullable()->after('worker_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropUnique('technicians_worker_id_unique');
            $table->dropColumn(['worker_id', 'worker_name']);
        });
    }
};
