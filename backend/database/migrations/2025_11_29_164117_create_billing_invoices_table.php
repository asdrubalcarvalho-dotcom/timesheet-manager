<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: ERP Invoice Tracking Table (Central Database)
 * 
 * Purpose:
 * - Store Stripe invoice metadata for ERP reconciliation
 * - Track invoice PDF URLs for accounting system download
 * - Monitor legal deadlines (15-day Portuguese tax law requirement)
 * - Flag invoices that need ERP processing
 * 
 * Table Location: Central database (NOT tenant-scoped)
 * 
 * Populated By:
 * - StripeWebhookController (invoice.* events)
 * - StripeSubscriptionManager (invoice creation)
 * 
 * Consumed By:
 * - InvoiceSyncService (listPending, markProcessed)
 * - ERP notification commands
 * - Billing admin panel
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            
            // Stripe identifiers
            $table->string('stripe_invoice_id')->unique()->comment('Stripe invoice ID (in_xxx)');
            $table->string('stripe_subscription_id')->nullable()->comment('Associated subscription ID (sub_xxx)');
            
            // Tenant context
            $table->char('tenant_id', 26)->comment('ULID tenant identifier');
            $table->string('tenant_slug')->index()->comment('Human-readable tenant slug');
            
            // Invoice status lifecycle
            $table->enum('status', [
                'draft',            // Invoice created but not finalized
                'open',             // Finalized, awaiting payment
                'paid',             // Successfully paid
                'uncollectible',    // Marked uncollectible by Stripe
                'void',             // Voided/canceled
            ])->default('draft')->index();
            
            // Billing period (from subscription)
            $table->timestamp('billing_period_start')->nullable()->comment('Subscription period start');
            $table->timestamp('billing_period_end')->nullable()->comment('Subscription period end');
            
            // Financial data (for reporting)
            $table->decimal('amount_due', 10, 2)->default(0)->comment('Total invoice amount in EUR');
            $table->decimal('amount_paid', 10, 2)->default(0)->comment('Amount paid in EUR');
            $table->string('currency', 3)->default('EUR');
            
            // PDF download
            $table->text('pdf_url')->nullable()->comment('Stripe-hosted invoice PDF URL');
            
            // ERP integration tracking
            $table->boolean('erp_processed')->default(false)->index()->comment('Flagged when sent to ERP');
            $table->timestamp('erp_processed_at')->nullable()->comment('Timestamp of ERP sync');
            $table->timestamp('erp_deadline_at')->nullable()->index()->comment('Legal deadline for ERP processing (15 days)');
            $table->text('erp_notes')->nullable()->comment('ERP sync notes or error messages');
            
            // Metadata (JSON) - for advanced ERP queries
            $table->json('metadata')->nullable()->comment('Stripe metadata (plan, addons, user_count, etc.)');
            
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
        Schema::dropIfExists('billing_invoices');
    }
};
