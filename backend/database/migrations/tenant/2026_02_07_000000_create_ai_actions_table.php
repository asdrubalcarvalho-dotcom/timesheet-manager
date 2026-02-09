<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('tenant_id')->nullable();
            $table->string('client_request_id', 64);
            $table->string('action', 64);
            $table->json('request_json')->nullable();
            $table->json('response_json')->nullable();
            $table->timestamps();

            $table->index(['actor_id', 'action']);
            $table->unique(['actor_id', 'client_request_id', 'action'], 'ai_actions_actor_request_action_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_actions');
    }
};
