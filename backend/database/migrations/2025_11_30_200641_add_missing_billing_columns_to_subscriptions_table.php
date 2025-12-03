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
        Schema::table('subscriptions', function (Blueprint $table) {
            // Only add columns if they don't exist
            if (!Schema::hasColumn('subscriptions', 'billing_period_started_at')) {
                $table->timestamp('billing_period_started_at')->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'last_renewal_at')) {
                $table->timestamp('last_renewal_at')->nullable();
            }
            if (!Schema::hasColumn('subscriptions', 'billing_gateway')) {
                $table->string('billing_gateway')->default('stripe');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['billing_period_started_at', 'last_renewal_at', 'billing_gateway']);
        });
    }
};
