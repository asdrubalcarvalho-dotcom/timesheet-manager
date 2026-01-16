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
        Schema::table('pending_tenant_signups', function (Blueprint $table) {
            if (! Schema::hasColumn('pending_tenant_signups', 'settings')) {
                $table->json('settings')->nullable()->after('timezone');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pending_tenant_signups', function (Blueprint $table) {
            if (Schema::hasColumn('pending_tenant_signups', 'settings')) {
                $table->dropColumn('settings');
            }
        });
    }
};
