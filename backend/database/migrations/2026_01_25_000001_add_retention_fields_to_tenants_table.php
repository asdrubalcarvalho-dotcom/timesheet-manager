<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'data_retention_until')) {
                $table->timestamp('data_retention_until')->nullable()->after('deactivated_at');
                $table->index('data_retention_until');
            }

            if (! Schema::hasColumn('tenants', 'scheduled_for_deletion_at')) {
                $table->timestamp('scheduled_for_deletion_at')->nullable()->after('data_retention_until');
                $table->index('scheduled_for_deletion_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'scheduled_for_deletion_at')) {
                $table->dropIndex(['scheduled_for_deletion_at']);
                $table->dropColumn('scheduled_for_deletion_at');
            }

            if (Schema::hasColumn('tenants', 'data_retention_until')) {
                $table->dropIndex(['data_retention_until']);
                $table->dropColumn('data_retention_until');
            }
        });
    }
};
