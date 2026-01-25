<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_metrics_daily', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26);
            $table->date('date');

            $table->unsignedBigInteger('timesheets_total')->default(0);
            $table->unsignedBigInteger('timesheets_today')->default(0);

            $table->unsignedBigInteger('expenses_total')->default(0);
            $table->unsignedBigInteger('expenses_today')->default(0);

            $table->unsignedBigInteger('users_total')->default(0);
            $table->unsignedBigInteger('users_active_today')->default(0);

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'date'], 'uniq_tenant_metrics_daily_tenant_date');
            $table->index('tenant_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_metrics_daily');
    }
};
