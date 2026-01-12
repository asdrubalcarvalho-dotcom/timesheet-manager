<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('social_accounts', 'provider')) {
                $table->string('provider')->after('user_id');
            }

            if (!Schema::hasColumn('social_accounts', 'provider_user_id')) {
                $table->string('provider_user_id')->after('provider');
            }

            if (!Schema::hasColumn('social_accounts', 'provider_email')) {
                $table->string('provider_email')->nullable()->after('provider_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('social_accounts', 'provider_email')) {
                $table->dropColumn('provider_email');
            }

            if (Schema::hasColumn('social_accounts', 'provider_user_id')) {
                $table->dropColumn('provider_user_id');
            }

            if (Schema::hasColumn('social_accounts', 'provider')) {
                $table->dropColumn('provider');
            }
        });
    }
};
