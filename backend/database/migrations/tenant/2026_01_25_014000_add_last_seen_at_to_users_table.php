<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'last_seen_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
