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
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider'); // 'google', 'microsoft'
            $table->string('provider_user_id'); // OAuth provider's unique user ID
            $table->string('provider_email')->nullable(); // Store only if needed for audit
            $table->timestamps();

            // Unique constraint: one provider identity per tenant
            $table->unique(['provider', 'provider_user_id'], 'social_accounts_provider_identity_unique');
            
            // Index for user lookup
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
