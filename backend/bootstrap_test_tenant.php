#!/usr/bin/env php
<?php

/**
 * Bootstrap Test Tenant for Webhook Testing
 * Creates tenant database, runs migrations, creates subscription
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

echo "🚀 Bootstrap Test Tenant\n";
echo "========================\n\n";

// 1. Create or get tenant
echo "1. Criando tenant...\n";
$tenant = Tenant::firstOrCreate(
    ['slug' => 'upg-to-ai'],
    [
        'id' => '01KBAS4F9BFT515EFMASWW6175',
        'name' => 'UPG to AI',
    ]
);
echo "   ✅ Tenant: {$tenant->slug} (ID: {$tenant->id})\n\n";

// 2. Create domain
echo "2. Criando domain...\n";
$domain = $tenant->domains()->firstOrCreate([
    'domain' => 'upg-to-ai.timeperk.localhost',
]);
echo "   ✅ Domain: {$domain->domain}\n\n";

// 3. Create tenant database
echo "3. Criando database do tenant...\n";
$dbName = 'timesheet_' . $tenant->id;
try {
    DB::statement("CREATE DATABASE IF NOT EXISTS `{$dbName}`");
    DB::statement("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO 'timesheet'@'%'");
    DB::statement("FLUSH PRIVILEGES");
    echo "   ✅ Database: {$dbName}\n\n";
} catch (Exception $e) {
    echo "   ⚠️  Database já existe ou erro: " . $e->getMessage() . "\n\n";
}

// 4. Run migrations on tenant database
echo "4. Executando migrations...\n";
$tenant->run(function () {
    Artisan::call('migrate', ['--force' => true]);
    echo "   ✅ Migrations executadas\n";
});
echo "\n";

// 5. Create subscription
echo "5. Criando subscription...\n";
$tenant->run(function () use ($tenant) {
    $subscription = \Modules\Billing\Models\Subscription::firstOrCreate(
        ['tenant_id' => $tenant->id],
        [
            'plan' => 'starter',
            'user_limit' => 3,
            'addons' => json_encode([]),
            'status' => 'active',
            'is_trial' => 0,
            'billing_period_ends_at' => now()->subDays(1), // Vencida ontem
            'next_renewal_at' => now()->subDays(1),
            'failed_renewal_attempts' => 0,
        ]
    );
    
    echo "   ✅ Subscription criada\n";
    echo "      Plan: {$subscription->plan}\n";
    echo "      Status: {$subscription->status}\n";
    echo "      Period ends: {$subscription->billing_period_ends_at}\n";
});

echo "\n✅ Bootstrap completo!\n";
echo "Tenant '{$tenant->slug}' pronto para testes de webhook.\n\n";
