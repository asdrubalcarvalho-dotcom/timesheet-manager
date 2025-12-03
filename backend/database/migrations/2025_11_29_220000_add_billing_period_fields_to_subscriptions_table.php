<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Add billing period fields if not present
            if (!Schema::hasColumn('subscriptions', 'billing_period_started_at')) {
                $table->dateTime('billing_period_started_at')->nullable()->after('trial_ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'billing_period_ends_at')) {
                $table->dateTime('billing_period_ends_at')->nullable()->after('billing_period_started_at');
            }
            if (!Schema::hasColumn('subscriptions', 'last_renewal_at')) {
                $table->dateTime('last_renewal_at')->nullable()->after('billing_period_ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'status')) {
                $table->string('status')->default('active')->after('last_renewal_at');
            }
            // Add pending plan fields if not present
            if (!Schema::hasColumn('subscriptions', 'pending_plan')) {
                $table->string('pending_plan')->nullable()->after('status');
            }
            if (!Schema::hasColumn('subscriptions', 'pending_user_limit')) {
                $table->integer('pending_user_limit')->nullable()->after('pending_plan');
            }
            if (!Schema::hasColumn('subscriptions', 'pending_plan_effective_at')) {
                $table->dateTime('pending_plan_effective_at')->nullable()->after('pending_user_limit');
            }
        });
    }

    public function down()
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'billing_period_started_at',
                'billing_period_ends_at',
                'last_renewal_at',
                'status',
                'pending_plan',
                'pending_user_limit',
                'pending_plan_effective_at',
            ]);
        });
    }
};
