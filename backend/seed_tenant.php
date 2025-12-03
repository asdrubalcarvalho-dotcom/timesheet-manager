<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = \Stancl\Tenancy\Database\Models\Tenant::find('test-company');
tenancy()->initialize($tenant);

\Artisan::call('db:seed', ['--class' => 'Database\Seeders\CompleteTenantSeeder']);

echo "✅ Tenant test-company seeded successfully!\n";
