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
            $table->boolean('is_trial')->default(false)->after('status');
            $table->timestamp('trial_ends_at')->nullable()->after('is_trial');
            
            // Index for querying active trials
            $table->index(['is_trial', 'trial_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['is_trial', 'trial_ends_at']);
            $table->dropColumn(['is_trial', 'trial_ends_at']);
        });
    }
};
