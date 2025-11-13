<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });

        Schema::table('project_members', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained('tenants')
                ->cascadeOnUpdate()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('technicians', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
