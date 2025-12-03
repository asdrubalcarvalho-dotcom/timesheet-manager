<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Phase 9: Add dunning engine fields for failed payment recovery
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Track number of failed renewal attempts
            $table->integer('failed_renewal_attempts')->default(0);
            
            // Grace period deadline - after this, subscription gets canceled
            $table->dateTime('grace_period_until')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['failed_renewal_attempts', 'grace_period_until']);
        });
    }
};
