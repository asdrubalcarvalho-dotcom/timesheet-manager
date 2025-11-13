<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resource_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->tinyInteger('allocation')->default(0); // 0-100%
            $table->unique(['resource_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_task');
    }
};
