<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This table stores license/seat information for each tenant.
     * Integrates with Stripe for billing and usage tracking.
     */
    public function up(): void
    {
        Schema::create('tenant_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 26)->unique(); // ULID from tenants table
            $table->integer('purchased_licenses')->default(1);
            $table->integer('used_licenses')->default(0);
            $table->decimal('price_per_license', 8, 2)->default(5.00); // €5.00 default
            $table->enum('billing_cycle', ['monthly', 'annual'])->default('monthly');
            $table->string('stripe_subscription_id')->nullable(); // Cashier subscription ID
            $table->string('stripe_price_id')->nullable(); // Stripe Price ID
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('auto_upgrade')->default(true); // Auto-add licenses when needed
            $table->json('metadata')->nullable(); // Additional configuration
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

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
        Schema::dropIfExists('tenant_licenses');
    }
};
