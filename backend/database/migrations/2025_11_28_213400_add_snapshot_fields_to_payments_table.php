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
        // Add Billing Model A snapshot fields to existing payments table
        Schema::table('payments', function (Blueprint $table) {
            // Billing snapshot at time of payment
            $table->string('plan')->nullable()->after('tenant_id');
            $table->integer('user_count')->nullable()->after('plan');
            $table->json('addons')->nullable()->after('user_count');
            
            // Billing period covered by this payment
            $table->date('cycle_start')->nullable()->after('currency');
            $table->date('cycle_end')->nullable()->after('cycle_start');
            
            // Stripe integration reference (if not using gateway_reference)
            $table->string('stripe_payment_intent_id')->nullable()->unique()->after('gateway_reference');
            
            // Index for performance
            $table->index(['tenant_id', 'cycle_start', 'cycle_end']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'cycle_start', 'cycle_end']);
            $table->dropColumn([
                'plan',
                'user_count',
                'addons',
                'cycle_start',
                'cycle_end',
                'stripe_payment_intent_id',
            ]);
        });
    }
};
