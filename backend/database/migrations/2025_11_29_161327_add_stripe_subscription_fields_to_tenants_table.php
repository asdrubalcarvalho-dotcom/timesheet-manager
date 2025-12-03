<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds Stripe Subscription-related fields to tenants table for Phase 2.
     * These fields store the connection between local tenants and Stripe Subscription objects.
     * 
     * Fields:
     * - stripe_subscription_id: Stripe's subscription ID (sub_xxxxx)
     * - active_addons: JSON array of currently active add-ons from Stripe subscription
     * - subscription_renews_at: Next renewal date from Stripe (current_period_end)
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Stripe Subscription ID (e.g., "sub_1ABCxyz...")
            $table->string('stripe_subscription_id')->nullable()->after('stripe_customer_id');
            $table->index('stripe_subscription_id');
            
            // Active add-ons synchronized from Stripe subscription items
            // Stored as JSON array, e.g., ["planning", "ai"]
            $table->json('active_addons')->nullable()->after('stripe_subscription_id');
            
            // Subscription renewal date from Stripe's current_period_end
            $table->timestamp('subscription_renews_at')->nullable()->after('active_addons');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['stripe_subscription_id']);
            $table->dropColumn(['stripe_subscription_id', 'active_addons', 'subscription_renews_at']);
        });
    }
};
