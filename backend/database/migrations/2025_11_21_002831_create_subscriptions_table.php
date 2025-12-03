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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 26)->index(); // ULID - indexed for lookups (no FK because tenants table is in central DB)
            $table->enum('plan', ['starter', 'team', 'enterprise'])->default('starter');
            $table->integer('user_limit')->nullable()->default(null); // NULL = unlimited (trial), integer = enforced limit
            $table->json('addons')->nullable(); // Array of addon keys: ['planning', 'ai']
            $table->timestamp('next_renewal_at')->nullable();
            $table->enum('status', ['active', 'canceled', 'past_due', 'trialing'])->default('active');
            $table->timestamps();

            // NOTE: No foreign key to tenants table because it's in the central database
            // Cross-database foreign keys are not supported in MySQL
            
            // One subscription per tenant
            $table->unique('tenant_id');
            
            // Indexes for querying
            $table->index('plan');
            $table->index('status');
            $table->index('next_renewal_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
