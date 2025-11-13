<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('tenancy:bootstrap-demo');
    }
}
