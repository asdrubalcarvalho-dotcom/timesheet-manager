<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores which features/modules are enabled for each tenant.
     * Supports feature flags, trial periods, and per-module configuration.
     */
    public function up(): void
    {
        Schema::create('tenant_features', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 26)->index(); // ULID from tenants table
            $table->string('module_name', 50); // 'timesheets', 'expenses', 'travel', 'planning', 'billing'
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('expires_at')->nullable(); // For trial periods
            $table->integer('max_users')->nullable(); // Per-module user limits
            $table->json('metadata')->nullable(); // Module-specific configuration
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Ensure one record per tenant per module
            $table->unique(['tenant_id', 'module_name'], 'unique_tenant_module');
            
            // Foreign key to tenants table (central DB)
            $table->foreign('tenant_id')
                  ->references('id')
                  ->on('tenants')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_features');
    }
};
