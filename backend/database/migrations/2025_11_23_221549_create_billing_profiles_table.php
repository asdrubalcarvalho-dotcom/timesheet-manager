<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * CENTRAL DATABASE - stores Stripe customer info per tenant
     */
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            
            // Tenant relationship (FK to tenants table)
            $table->char('tenant_id', 26); // ULID
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            
            // Payment gateway info
            $table->string('gateway')->default('stripe'); // 'stripe' or 'fake'
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('default_payment_method')->nullable();
            
            // Billing contact info
            $table->string('billing_email')->nullable();
            $table->string('billing_name')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('tenant_id');
            $table->index('stripe_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_profiles');
    }
};
