<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pending_tenant_signups', function (Blueprint $table) {
            if (!Schema::hasColumn('pending_tenant_signups', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('verified');
                $table->index('email_verified_at');
            }

            if (!Schema::hasColumn('pending_tenant_signups', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('email_verified_at');
                $table->index('completed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pending_tenant_signups', function (Blueprint $table) {
            if (Schema::hasColumn('pending_tenant_signups', 'completed_at')) {
                $table->dropIndex(['completed_at']);
                $table->dropColumn('completed_at');
            }

            if (Schema::hasColumn('pending_tenant_signups', 'email_verified_at')) {
                $table->dropIndex(['email_verified_at']);
                $table->dropColumn('email_verified_at');
            }
        });
    }
};
