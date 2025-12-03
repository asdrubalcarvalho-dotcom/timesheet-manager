<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('billing_country', 2)->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('billing_vat_number')->nullable();
        });
    }

    public function down()
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'billing_country',
                'billing_address',
                'billing_postal_code',
                'billing_vat_number'
            ]);
        });
    }
};
