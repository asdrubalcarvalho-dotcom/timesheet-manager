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
            if (! Schema::hasColumn('pending_tenant_signups', 'legal_accepted_at')) {
                $table->timestamp('legal_accepted_at')->nullable()->after('timezone');
                $table->index('legal_accepted_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pending_tenant_signups', function (Blueprint $table) {
            if (Schema::hasColumn('pending_tenant_signups', 'legal_accepted_at')) {
                $table->dropIndex(['legal_accepted_at']);
                $table->dropColumn('legal_accepted_at');
            }
        });
    }
};
