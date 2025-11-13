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
        Schema::table('tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('tasks', 'estimated_hours')) {
                $table->decimal('estimated_hours', 5, 2)->nullable()->after('name');
            }
            if (!Schema::hasColumn('tasks', 'start_date')) {
                $table->date('start_date')->nullable()->after('estimated_hours');
            }
            if (!Schema::hasColumn('tasks', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
            if (!Schema::hasColumn('tasks', 'progress')) {
                $table->unsignedTinyInteger('progress')->default(0)->after('end_date');
            }
            if (!Schema::hasColumn('tasks', 'dependencies')) {
                $table->json('dependencies')->nullable()->after('progress');
            }
        });

        Schema::table('resources', function (Blueprint $table) {
            if (!Schema::hasColumn('resources', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained()
                    ->nullOnDelete()
                    ->after('meta');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            if (Schema::hasColumn('tasks', 'dependencies')) {
                $table->dropColumn('dependencies');
            }
            if (Schema::hasColumn('tasks', 'progress')) {
                $table->dropColumn('progress');
            }
            if (Schema::hasColumn('tasks', 'end_date')) {
                $table->dropColumn('end_date');
            }
            if (Schema::hasColumn('tasks', 'start_date')) {
                $table->dropColumn('start_date');
            }
            if (Schema::hasColumn('tasks', 'estimated_hours')) {
                $table->dropColumn('estimated_hours');
            }
        });

        Schema::table('resources', function (Blueprint $table) {
            if (Schema::hasColumn('resources', 'user_id')) {
                $table->dropConstrainedForeignId('user_id');
            }
        });
    }
};
