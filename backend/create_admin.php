<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$tenant = \Stancl\Tenancy\Database\Models\Tenant::find('test-company');
tenancy()->initialize($tenant);

// Create admin user
$user = \App\Models\User::create([
    'name' => 'Admin User',
    'email' => 'admin@testcompany.test',
    'password' => \Illuminate\Support\Facades\Hash::make('admin123'),
]);

echo "✅ Admin user created!\n";
echo "Email: admin@testcompany.test\n";
echo "Password: admin123\n";
