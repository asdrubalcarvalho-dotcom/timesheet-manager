<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Configure manual tenant connection
$config = config('database.connections.tenant');
$config['database'] = 'timesheet_01KBAS4F9BFT515EFMASWW6175';
config(['database.connections.tenant_manual' => $config]);
DB::purge('tenant_manual');

echo "Running migrations on timesheet_01KBAS4F9BFT515EFMASWW6175...\n";

// Create migrations table first
DB::connection('tenant_manual')->statement('
    CREATE TABLE IF NOT EXISTS migrations (
        id int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
        migration varchar(255) NOT NULL,
        batch int NOT NULL
    ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
');

echo "Migrations table created.\n";

// Run migrations
$migrator = app('migrator');
$migrator->setConnection('tenant_manual');
$migrator->run([database_path('migrations/tenant')], ['pretend' => false]);

echo "Migrations executed successfully!\n";
