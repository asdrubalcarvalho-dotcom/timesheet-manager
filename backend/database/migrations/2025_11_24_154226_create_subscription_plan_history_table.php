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
        Schema::create('subscription_plan_history', function (Blueprint $table) {
            $table->id();
            $table->char('tenant_id', 26); // ULID
            $table->enum('previous_plan', ['starter', 'team', 'enterprise'])->nullable();
            $table->enum('new_plan', ['starter', 'team', 'enterprise']);
            $table->integer('previous_user_limit')->nullable();
            $table->integer('new_user_limit')->nullable();
            $table->timestamp('changed_at');
            $table->string('changed_by')->nullable(); // User email or system
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plan_history');
    }
};
