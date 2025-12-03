<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: Payment Failure Tracking Table (Central Database)
 * 
 * Purpose:
 * - Track all Stripe payment failures for dunning system
 * - Monitor failure reasons and resolution status
 * - Support automated retry logic and email reminders
 * - Link failures to invoices and payment intents
 * 
 * Table Location: Central database (NOT tenant-scoped)
 * 
 * Populated By:
 * - StripeWebhookController (invoice.payment_failed, charge.failed events)
 * - Payment failure detection logic
 * 
 * Consumed By:
 * - Dunning command (billing:dunning-check)
 * - Subscription status service
 * - Admin dashboard
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_payment_failures', function (Blueprint $table) {
            $table->id();
            
            // Tenant context
            $table->char('tenant_id', 26)->index()->comment('ULID tenant identifier');
            $table->string('tenant_slug')->index()->comment('Human-readable tenant slug');
            
            // Stripe references
            $table->string('stripe_invoice_id')->nullable()->index()->comment('Related invoice (in_xxx)');
            $table->string('stripe_payment_intent_id')->nullable()->index()->comment('Related payment intent (pi_xxx)');
            $table->string('stripe_charge_id')->nullable()->comment('Related charge (ch_xxx)');
            
            // Failure details
            $table->string('reason')->nullable()->comment('Stripe failure reason code');
            $table->text('error_message')->nullable()->comment('Full error message from Stripe');
            $table->decimal('amount', 10, 2)->default(0)->comment('Failed payment amount in EUR');
            
            // Status tracking
            $table->enum('status', ['pending', 'retrying', 'resolved', 'abandoned'])->default('pending')->index();
            $table->timestamp('failed_at')->nullable()->comment('When the payment first failed');
            $table->timestamp('resolved_at')->nullable()->comment('When resolved (if applicable)');
            
            // Dunning tracking
            $table->integer('reminder_count')->default(0)->comment('Number of reminder emails sent');
            $table->timestamp('last_reminder_at')->nullable()->comment('Last dunning email timestamp');
            $table->timestamp('next_reminder_at')->nullable()->index()->comment('Next scheduled reminder');
            
            // Resolution metadata
            $table->string('resolution_method')->nullable()->comment('How it was resolved: auto_retry, manual, canceled');
            $table->text('notes')->nullable()->comment('Internal notes');
            
            $table->timestamps();
            
            // Foreign key to tenants table
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
        Schema::dropIfExists('billing_payment_failures');
    }
};
