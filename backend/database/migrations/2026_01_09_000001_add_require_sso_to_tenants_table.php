<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenants', 'require_sso')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->boolean('require_sso')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenants', 'require_sso')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('require_sso');
            });
        }
    }
};
