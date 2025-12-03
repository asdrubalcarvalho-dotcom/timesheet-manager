<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: Subscription Status Tracking Fields
 * 
 * Purpose:
 * - Add comprehensive subscription status tracking to tenants table
 * - Support payment failure detection and dunning workflows
 * - Track subscription lifecycle events (pause, cancel, etc.)
 * - Enable real-time subscription health monitoring
 * 
 * New Fields:
 * - subscription_status: Current Stripe subscription status
 * - subscription_last_event: Most recent webhook event type
 * - subscription_last_status_change_at: Timestamp of last status change
 * - is_paused: Quick flag for paused subscriptions
 * 
 * Updated By:
 * - SubscriptionStatusService
 * - Stripe webhook handlers
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Subscription status (matches Stripe subscription.status)
            $table->enum('subscription_status', [
                'active',          // Subscription is active and paid
                'past_due',        // Payment failed, in retry period
                'unpaid',          // Payment failed after retries
                'canceled',        // Subscription canceled
                'incomplete',      // Initial payment pending
                'incomplete_expired', // Initial payment failed
                'trialing',        // In trial period
                'paused',          // Manually paused
            ])->default('active')->after('subscription_renews_at')->index();
            
            // Last webhook event received
            $table->string('subscription_last_event')->nullable()->after('subscription_status')
                ->comment('Last Stripe event: customer.subscription.updated, etc');
            
            // Status change tracking
            $table->timestamp('subscription_last_status_change_at')->nullable()->after('subscription_last_event')
                ->comment('When subscription_status last changed');
            
            // Pause flag (for quick queries)
            $table->boolean('is_paused')->default(false)->after('subscription_last_status_change_at')->index()
                ->comment('Quick flag for paused subscriptions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_status',
                'subscription_last_event',
                'subscription_last_status_change_at',
                'is_paused',
            ]);
        });
    }
};
