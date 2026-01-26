<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action');
            $table->char('tenant_id', 26)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
    }
};
