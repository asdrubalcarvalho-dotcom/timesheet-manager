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
        Schema::create('plan_change_history', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 26)->comment('Tenant ULID');
            $table->string('old_plan')->nullable()->comment('Previous plan (null for first subscription)');
            $table->string('new_plan')->comment('New plan after change');
            $table->json('old_addons')->nullable()->comment('Previous addon configuration');
            $table->json('new_addons')->nullable()->comment('New addon configuration');
            $table->integer('old_user_limit')->nullable()->comment('Previous user limit');
            $table->integer('new_user_limit')->nullable()->comment('New user limit');
            $table->decimal('old_price', 10, 2)->nullable()->comment('Previous monthly price');
            $table->decimal('new_price', 10, 2)->nullable()->comment('New monthly price');
            $table->enum('change_type', ['upgrade', 'downgrade', 'addon_change', 'user_limit_change', 'initial'])->comment('Type of plan change');
            $table->text('reason')->nullable()->comment('Optional reason for change');
            $table->timestamps();
            
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'created_at'], 'idx_tenant_history');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_change_history');
    }
};
