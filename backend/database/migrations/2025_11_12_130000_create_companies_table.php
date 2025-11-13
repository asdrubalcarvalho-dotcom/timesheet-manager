<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('country', 2)->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('billing_email')->nullable();
            $table->string('support_email')->nullable();
            $table->string('website')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('status')->default('active');
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
