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
        Schema::table('expenses', function (Blueprint $table) {
            // Expense type: reimbursement, mileage, company_card
            $table->enum('expense_type', ['reimbursement', 'mileage', 'company_card'])
                ->default('reimbursement')
                ->after('status');
            
            // Mileage specific fields
            $table->decimal('distance_km', 8, 2)->nullable()->after('expense_type');
            $table->decimal('rate_per_km', 8, 2)->nullable()->after('distance_km');
            $table->string('vehicle_type', 50)->nullable()->after('rate_per_km'); // car, motorcycle, etc
            
            // Finance workflow
            $table->foreignId('finance_approved_by')->nullable()
                ->after('vehicle_type')
                ->constrained('users')
                ->onDelete('set null');
            $table->timestamp('finance_approved_at')->nullable()->after('finance_approved_by');
            $table->text('finance_notes')->nullable()->after('finance_approved_at');
            
            // Payment tracking
            $table->timestamp('paid_at')->nullable()->after('finance_notes');
            $table->string('payment_reference', 100)->nullable()->after('paid_at');
            
            // Company card import reference (future use)
            $table->string('card_transaction_id', 100)->nullable()->after('payment_reference');
            $table->timestamp('transaction_date')->nullable()->after('card_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['finance_approved_by']);
            $table->dropColumn([
                'expense_type',
                'distance_km',
                'rate_per_km',
                'vehicle_type',
                'finance_approved_by',
                'finance_approved_at',
                'finance_notes',
                'paid_at',
                'payment_reference',
                'card_transaction_id',
                'transaction_date',
            ]);
        });
    }
};
