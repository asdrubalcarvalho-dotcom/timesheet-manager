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
        Schema::create('pending_tenant_signups', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('slug')->unique();
            $table->string('admin_name');
            $table->string('admin_email');
            $table->string('password_hash');
            $table->string('verification_token', 64)->unique();
            $table->string('industry')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('timezone', 50)->default('UTC');
            $table->timestamp('expires_at');
            $table->boolean('verified')->default(false);
            $table->timestamps();

            $table->index('verification_token');
            $table->index('expires_at');
            $table->index('admin_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_tenant_signups');
    }
};
